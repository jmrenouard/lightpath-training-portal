"""Unit and integration tests for versioned MkDocs Material publishing and IONOS deployment."""

from __future__ import annotations

import importlib.util
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path
from types import ModuleType
from typing import Any
from urllib.parse import unquote

import pytest
import yaml

REPO_ROOT = Path(__file__).resolve().parents[1]
WORKFLOW_PATH = REPO_ROOT / ".github" / "workflows" / "docs-publish.yml"
MKDOCS_CONFIG_PATH = REPO_ROOT / "mkdocs.yml"
HOOKS_SCRIPT_PATH = REPO_ROOT / "scripts" / "mkdocs_hooks.py"
BUILD_SCRIPT_PATH = REPO_ROOT / "scripts" / "build_versioned_docs.py"
REQUIREMENTS_DOCS_PATH = REPO_ROOT / "requirements-docs.txt"
GITIGNORE_PATH = REPO_ROOT / ".gitignore"

SHA40_RE = re.compile(r"^[0-9a-fA-F]{40}$")
HTML_LOCAL_REF_RE = re.compile(r"""(?:src|href)\s*=\s*(["'])([^"']+)\1""", re.IGNORECASE)
EXTERNAL_PREFIXES = ("http://", "https://", "//", "mailto:", "tel:", "data:", "javascript:", "#")


class _MkDocsYamlLoader(yaml.SafeLoader):
    """Safe YAML loader supporting PyMdownx `!!python/name:` tag references as strings."""


def _python_name_multi_constructor(
    loader: yaml.Loader,
    tag_suffix: str,
    node: yaml.Node,
) -> str:
    del loader, node
    return f"python/name:{tag_suffix}"


_MkDocsYamlLoader.add_multi_constructor(
    "tag:yaml.org,2002:python/name:",
    _python_name_multi_constructor,
)


class _AttrDict(dict[str, Any]):
    """Dictionary exposing keys as attributes to mimic MkDocsConfig in hook unit tests."""

    def __getattr__(self, key: str) -> Any:
        try:
            return self[key]
        except KeyError as exc:
            raise AttributeError(key) from exc

    def __setattr__(self, key: str, value: Any) -> None:
        self[key] = value


def _load_mkdocs_config() -> dict[str, Any]:
    assert MKDOCS_CONFIG_PATH.is_file(), "mkdocs.yml must exist at repository root"
    loader = _MkDocsYamlLoader(MKDOCS_CONFIG_PATH.read_text(encoding="utf-8"))
    try:
        parsed = loader.get_single_data()
    finally:
        loader.dispose()
    assert isinstance(parsed, dict), "mkdocs.yml must parse into a YAML mapping"
    return parsed


def _load_script_module(module_name: str, script_path: Path) -> ModuleType:
    assert script_path.is_file(), f"Missing script module: {script_path}"
    scripts_dir = str(script_path.parent)
    added_sys_path = False
    if scripts_dir not in sys.path:
        sys.path.insert(0, scripts_dir)
        added_sys_path = True
    try:
        spec = importlib.util.spec_from_file_location(module_name, script_path)
        assert spec is not None and spec.loader is not None, f"Cannot load spec for {script_path}"
        module = importlib.util.module_from_spec(spec)
        sys.modules[module_name] = module
        spec.loader.exec_module(module)
        return module
    finally:
        if added_sys_path and scripts_dir in sys.path:
            sys.path.remove(scripts_dir)


def _flatten_nav_paths(node: Any) -> list[str]:
    paths: list[str] = []
    if isinstance(node, str):
        paths.append(node)
    elif isinstance(node, list):
        for item in node:
            paths.extend(_flatten_nav_paths(item))
    elif isinstance(node, dict):
        for val in node.values():
            paths.extend(_flatten_nav_paths(val))
    return paths


def _find_nav_section(nav: list[Any], section_name: str) -> Any:
    for item in nav:
        if isinstance(item, dict) and section_name in item:
            return item[section_name]
    return None


def _collect_workflow_steps(job_cfg: dict[str, Any]) -> list[dict[str, Any]]:
    steps = job_cfg.get("steps", [])
    assert isinstance(steps, list)
    return [step for step in steps if isinstance(step, dict)]


# ==============================================================================
# 1. mkdocs.yml and Portal Assets Verification
# ==============================================================================


def test_mkdocs_yml_configuration_and_security_exclusions() -> None:
    """Verify mkdocs.yml Material config, Mermaid, KaTeX, mike switcher, hooks, and security exclusions."""
    config = _load_mkdocs_config()

    assert config.get("site_name") == "LightPath Training Portal Documentation"
    assert config.get("site_url") == "https://lms.lightpath.fr/"
    assert config.get("repo_name") == "jmrenouard/lightpath-training-portal"
    assert config.get("repo_url") == "https://github.com/jmrenouard/lightpath-training-portal"
    assert config.get("docs_dir") == "docs"
    assert "scripts/mkdocs_hooks.py" in config.get("hooks", [])

    theme = config.get("theme", {})
    assert isinstance(theme, dict)
    assert theme.get("name") == "material"
    assert theme.get("language") == "en"
    assert "navigation.tabs" in theme.get("features", [])
    assert "navigation.indexes" in theme.get("features", [])

    extra = config.get("extra", {})
    assert extra.get("version", {}).get("provider") == "mike"
    assert extra.get("version", {}).get("default") == "latest"

    extra_css = config.get("extra_css", [])
    assert any("katex" in item and item.endswith(".css") for item in extra_css)
    assert "stylesheets/extra.css" in extra_css

    extra_js = config.get("extra_javascript", [])
    assert "javascripts/katex.js" in extra_js
    assert any("katex.min.js" in item for item in extra_js)
    assert any("auto-render.min.js" in item for item in extra_js)

    extensions = config.get("markdown_extensions", [])
    ext_names: set[str] = set()
    superfences_cfg: dict[str, Any] = {}
    for ext in extensions:
        if isinstance(ext, str):
            ext_names.add(ext)
        elif isinstance(ext, dict):
            ext_names.update(ext.keys())
            if "pymdownx.superfences" in ext:
                superfences_cfg = ext["pymdownx.superfences"] or {}

    assert "admonition" in ext_names
    assert "pymdownx.arithmatex" in ext_names
    assert "pymdownx.superfences" in ext_names

    custom_fences = superfences_cfg.get("custom_fences", [])
    mermaid_fences = [
        fence for fence in custom_fences if isinstance(fence, dict) and fence.get("name") == "mermaid"
    ]
    assert len(mermaid_fences) == 1, "pymdownx.superfences must configure a mermaid custom fence"
    assert mermaid_fences[0].get("class") == "mermaid"
    assert "fence_code_format" in str(mermaid_fences[0].get("format", ""))

    raw_exclude = config.get("exclude_docs", "")
    exclude_text = "\n".join(raw_exclude) if isinstance(raw_exclude, list) else str(raw_exclude)
    for pattern in ("internal/**", "*.kdbx", "*.keyx", ".env", "initial_admin_credentials.txt"):
        assert pattern in exclude_text, f"exclude_docs must exclude '{pattern}'"

    nav = config.get("nav", [])
    assert isinstance(nav, list) and len(nav) == 6
    tab_names = [next(iter(entry.keys())) for entry in nav if isinstance(entry, dict)]
    assert tab_names == [
        "Home",
        "Tutorials",
        "How-To Guides",
        "Reference",
        "Explanation",
        "Releases & Governance",
    ]

    assert (REPO_ROOT / "docs" / "index.md").is_file()
    assert (REPO_ROOT / "docs" / "stylesheets" / "extra.css").is_file()
    katex_js = REPO_ROOT / "docs" / "javascripts" / "katex.js"
    assert katex_js.is_file()
    katex_js_text = katex_js.read_text(encoding="utf-8")
    assert "document$.subscribe" in katex_js_text
    assert "renderMathInElement" in katex_js_text
    assert (REPO_ROOT / "docs" / "assets" / "logo.svg").is_file()
    assert (REPO_ROOT / "docs" / "assets" / "header-banner.svg").is_file()


# ==============================================================================
# 2. scripts/mkdocs_hooks.py Unit Tests
# ==============================================================================


def test_mkdocs_hooks_convert_github_alerts_and_ignore_code_blocks() -> None:
    """Verify all 5 GitHub alerts convert to Material admonitions while fenced blocks stay untouched."""
    hooks = _load_script_module("mkdocs_hooks", HOOKS_SCRIPT_PATH)

    sample = (
        "Intro paragraph.\n\n"
        "> [!NOTE]\n"
        "> Useful context line.\n\n"
        "> [!TIP]\n"
        "> Helpful optimization.\n\n"
        "> [!IMPORTANT]\n"
        "> Essential requirement.\n\n"
        "> [!WARNING]\n"
        "> Watch out for drift.\n\n"
        "> [!CAUTION]\n"
        "> High risk operation.\n\n"
        "```markdown\n"
        "> [!NOTE]\n"
        "> Literal example inside fenced code.\n"
        "```\n"
    )

    converted = hooks.convert_github_alerts(sample)

    assert "!!! note" in converted
    assert "!!! tip" in converted
    assert "!!! info" in converted
    assert "!!! warning" in converted
    assert "!!! danger" in converted
    assert "Useful context line." in converted
    assert "High risk operation." in converted

    fenced_part = converted.split("```markdown", 1)[1]
    assert "> [!NOTE]" in fenced_part
    outside_fenced = converted.split("```markdown", 1)[0]
    for marker in ("[!" + tag + "]" for tag in ("NOTE", "TIP", "IMPORTANT", "WARNING", "CAUTION")):
        assert marker not in outside_fenced


def test_mkdocs_hooks_gfm_slugify() -> None:
    """Verify gfm_slugify produces GitHub-compatible TOC anchors across EN and accented headings."""
    hooks = _load_script_module("mkdocs_hooks", HOOKS_SCRIPT_PATH)

    assert hooks.gfm_slugify("Documentation & Governance Hub") == "documentation--governance-hub"
    assert hooks.gfm_slugify("12-Dimension Feature Comparison") == "12-dimension-feature-comparison"
    assert hooks.gfm_slugify("Why LightPath?") == "why-lightpath"
    assert hooks.gfm_slugify("Architecture & Sécurité (Docker)") == "architecture--sécurité-docker"
    assert hooks.gfm_slugify("cli_flags") == "cli_flags"
    assert hooks.gfm_slugify("Alpha Beta", separator="_") == "alpha_beta"


def test_mkdocs_hooks_rewrite_relative_links_virtual_html_and_exclusions() -> None:
    """Verify virtual root links, docs/internal/ GitHub URLs, HTML <img src> depth, and excluded targets."""
    hooks = _load_script_module("mkdocs_hooks", HOOKS_SCRIPT_PATH)

    overview_md = (
        '<img src="docs/assets/header-banner.svg" alt="Banner" />\n'
        '<img src="docs/assets/logo.svg" alt="Logo" />\n'
        "- [Overview](README.md)\n"
        "- [Changelog](CHANGELOG.md)\n"
        "- [Contributing](CONTRIBUTING.md)\n"
        "- [Context](PROJECT-CONTEXT.md)\n"
        "- [Security Policy](SECURITY.md)\n"
        "- [Portal](docs/index.md)\n"
        "- [Security Guide](docs/SECURITY.md)\n"
        "- [Internal RCA](docs/internal/REGISTRE_RCA.md)\n"
        "- [Internal Dir](docs/internal/)\n"
        "- [Auth Core](src/Core/Auth.php)\n"
        "- [Vault](secrets.kdbx)\n"
        "- [Key](vault.keyx)\n"
        "- [Env](.env)\n"
        "- [InitCreds](storage/data/initial_admin_credentials.txt)\n"
        "```markdown\n"
        '[Preserved](README.md)\n'
        '<img src="docs/assets/logo.svg" />\n'
        "```\n"
    )

    rewritten_overview = hooks.rewrite_relative_links(
        overview_md,
        page_src_path="overview.md",
        ref="v2.0.0",
        docs_dir=REPO_ROOT / "docs",
        use_directory_urls=True,
    )
    outside_fence, fenced_part = rewritten_overview.split("```markdown", 1)

    assert '<img src="../assets/header-banner.svg"' in outside_fence
    assert '<img src="../assets/logo.svg"' in outside_fence
    assert "](overview.md)" in outside_fence
    assert "](CHANGELOG.md)" in outside_fence
    assert "](CONTRIBUTING.md)" in outside_fence
    assert "](PROJECT-CONTEXT.md)" in outside_fence
    assert "](SECURITY-POLICY.md)" in outside_fence
    assert "](index.md)" in outside_fence
    assert "](SECURITY.md)" in outside_fence
    assert (
        "](https://github.com/jmrenouard/lightpath-training-portal/blob/v2.0.0/docs/internal/REGISTRE_RCA.md)"
        in outside_fence
    )
    assert (
        "](https://github.com/jmrenouard/lightpath-training-portal/tree/v2.0.0/docs/internal/)"
        in outside_fence
    )
    assert (
        "](https://github.com/jmrenouard/lightpath-training-portal/blob/v2.0.0/src/Core/Auth.php)"
        in outside_fence
    )

    for forbidden_target in (
        "secrets.kdbx",
        "vault.keyx",
        "(.env)",
        "initial_admin_credentials.txt",
    ):
        assert forbidden_target not in outside_fence
    for kept_label in ("Vault", "Key", "Env", "InitCreds"):
        assert kept_label in outside_fence

    assert "[Preserved](README.md)" in fenced_part
    assert '<img src="docs/assets/logo.svg" />' in fenced_part

    rewritten_flat = hooks.rewrite_relative_links(
        '<img src="docs/assets/header-banner.svg" alt="Banner" />\n',
        page_src_path="overview.md",
        ref="v2.0.0",
        use_directory_urls=False,
    )
    assert '<img src="assets/header-banner.svg"' in rewritten_flat

    arch_md = (
        '<img src="assets/logo.svg" alt="Logo" />\n'
        "- [README](../README.md)\n"
        "- [Policy](../SECURITY.md)\n"
        "- [Internal Log](internal/JOURNAL_DE_BORD.md)\n"
        "- [Bootstrap](../src/bootstrap.php)\n"
        "- [Creds](../initial_admin_credentials.txt)\n"
    )
    rewritten_arch = hooks.rewrite_relative_links(
        arch_md,
        page_src_path="ARCHITECTURE.md",
        ref="v2.0.0",
        docs_dir=REPO_ROOT / "docs",
        use_directory_urls=True,
    )
    assert '<img src="../assets/logo.svg"' in rewritten_arch
    assert "](overview.md)" in rewritten_arch
    assert "](SECURITY-POLICY.md)" in rewritten_arch
    assert (
        "](https://github.com/jmrenouard/lightpath-training-portal/blob/v2.0.0/docs/internal/JOURNAL_DE_BORD.md)"
        in rewritten_arch
    )
    assert (
        "](https://github.com/jmrenouard/lightpath-training-portal/blob/v2.0.0/src/bootstrap.php)"
        in rewritten_arch
    )
    assert "initial_admin_credentials.txt" not in rewritten_arch


def test_mkdocs_hooks_diataxis_depth_links_and_historical_fallbacks(tmp_path: Path) -> None:
    """Verify depth-1/depth-2 Diataxis rewriting and immutable v2.0.0 historical fallbacks."""
    hooks = _load_script_module("mkdocs_hooks", HOOKS_SCRIPT_PATH)

    depth1_md = (
        "- [Root](../../README.md)\n"
        "- [Changelog](../../CHANGELOG.md)\n"
        "- [Contributing](../../CONTRIBUTING.md)\n"
        "- [Security Policy](../../SECURITY.md)\n"
        "- [Portal](../index.md)\n"
        "- [Auth](../../src/Core/Auth.php)\n"
        "- [Ref](../03-reference/cli-and-scripts/cli-utilities-and-exit-codes.md)\n"
    )
    rewritten_d1 = hooks.rewrite_relative_links(
        depth1_md,
        page_src_path="01-tutorials/01-local-quickstart-sandbox.md",
        ref="main",
    )
    assert "](../overview.md)" in rewritten_d1
    assert "](../CHANGELOG.md)" in rewritten_d1
    assert "](../CONTRIBUTING.md)" in rewritten_d1
    assert "](../SECURITY-POLICY.md)" in rewritten_d1
    assert "](../index.md)" in rewritten_d1
    assert (
        "](https://github.com/jmrenouard/lightpath-training-portal/blob/main/src/Core/Auth.php)"
        in rewritten_d1
    )
    assert "](../03-reference/cli-and-scripts/cli-utilities-and-exit-codes.md)" in rewritten_d1

    depth2_md = (
        "- [Root](../../../README.md)\n"
        "- [Changelog](../../../CHANGELOG.md)\n"
        "- [Portal](../../index.md)\n"
        "- [Config](../../../config/app.php)\n"
        "- [Ref](../../03-reference/configuration/environment-and-feature-flags.md)\n"
    )
    rewritten_d2 = hooks.rewrite_relative_links(
        depth2_md,
        page_src_path="02-how-to-guides/deployment/deploy-portal-docker-and-pdo.md",
        ref="main",
    )
    assert "](../../overview.md)" in rewritten_d2
    assert "](../../CHANGELOG.md)" in rewritten_d2
    assert "](../../index.md)" in rewritten_d2
    assert (
        "](https://github.com/jmrenouard/lightpath-training-portal/blob/main/config/app.php)"
        in rewritten_d2
    )
    assert "](../../03-reference/configuration/environment-and-feature-flags.md)" in rewritten_d2

    # Immutable v2.0.0 snapshot: lacks Diataxis quadrant dirs, MOODLE_COMPARISON.md, ACTION_LOGBOOK.md, RCA_REGISTER.md
    v200_docs = tmp_path / "v200_docs"
    v200_docs.mkdir(parents=True)
    for rel in (
        "ADMIN_AND_TRAINER_GUIDE.md",
        "API_REFERENCE.md",
        "ARCHITECTURE.md",
        "COURSE_PACK_GUIDE.md",
        "DEPLOYMENT_AND_OPERATIONS.md",
        "DEVELOPER_AND_TESTING_GUIDE.md",
        "DOCKER_LABS_GUIDE.md",
        "QUALITY_AUDIT_PONYTAIL.md",
        "SECURITY.md",
    ):
        (v200_docs / rel).write_text(f"# {rel}\n", encoding="utf-8")

    portal_links = (
        "- [T](01-tutorials/index.md)\n"
        "- [T1](01-tutorials/01-local-quickstart-sandbox.md)\n"
        "- [H](02-how-to-guides/index.md)\n"
        "- [R](03-reference/index.md)\n"
        "- [E](04-explanation/index.md)\n"
        "- [Moodle](MOODLE_COMPARISON.md)\n"
        "- [Logbook](ACTION_LOGBOOK.md)\n"
        "- [RCA](RCA_REGISTER.md)\n"
        "- [Sec](SECURITY.md)\n"
        "- [Changelog](../CHANGELOG.md)\n"
    )
    rewritten_v200 = hooks.rewrite_relative_links(
        portal_links,
        page_src_path="index.md",
        ref="v2.0.0",
        docs_dir=v200_docs,
    )
    assert "- [T](COURSE_PACK_GUIDE.md)" in rewritten_v200
    assert "- [T1](COURSE_PACK_GUIDE.md)" in rewritten_v200
    assert "- [H](DEPLOYMENT_AND_OPERATIONS.md)" in rewritten_v200
    assert "- [R](API_REFERENCE.md)" in rewritten_v200
    assert "- [E](ARCHITECTURE.md)" in rewritten_v200
    assert "- [Moodle](overview.md)" in rewritten_v200
    assert "- [Logbook](overview.md)" in rewritten_v200
    assert "- [RCA](overview.md)" in rewritten_v200
    assert "- [Sec](SECURITY.md)" in rewritten_v200
    assert "- [Changelog](CHANGELOG.md)" in rewritten_v200

    # Modern docs_dir: preserves quadrant links and resolves directory links to index.md
    modern_docs = tmp_path / "modern_docs"
    for quadrant in ("01-tutorials", "02-how-to-guides", "03-reference", "04-explanation"):
        (modern_docs / quadrant).mkdir(parents=True)
        (modern_docs / quadrant / "index.md").write_text(f"# {quadrant}\n", encoding="utf-8")
    (modern_docs / "01-tutorials" / "01-local-quickstart-sandbox.md").write_text(
        "# Lab\n", encoding="utf-8"
    )

    rewritten_modern = hooks.rewrite_relative_links(
        "- [T](01-tutorials/index.md)\n"
        "- [TDir](01-tutorials/)\n"
        "- [T1](01-tutorials/01-local-quickstart-sandbox.md)\n",
        page_src_path="index.md",
        ref="main",
        docs_dir=modern_docs,
    )
    assert "- [T](01-tutorials/index.md)" in rewritten_modern
    assert "- [TDir](01-tutorials/index.md)" in rewritten_modern
    assert "- [T1](01-tutorials/01-local-quickstart-sandbox.md)" in rewritten_modern


def test_mkdocs_hooks_prune_nav_for_existing_files_and_discover_release_notes(tmp_path: Path) -> None:
    """Verify nav pruning removes missing entries/sections, keeps virtual docs, and sorts release notes."""
    hooks = _load_script_module("mkdocs_hooks", HOOKS_SCRIPT_PATH)

    docs_dir = tmp_path / "docs"
    docs_dir.mkdir()
    (docs_dir / "index.md").write_text("# Home\n", encoding="utf-8")
    (docs_dir / "ACTION_LOGBOOK.md").write_text("# Logbook\n", encoding="utf-8")
    (docs_dir / "RELEASE_NOTES_v2.0.0.md").write_text("# v2.0.0\n", encoding="utf-8")
    (docs_dir / "RELEASE_NOTES_v2.0.1.md").write_text("# v2.0.1\n", encoding="utf-8")
    (docs_dir / "RELEASE_NOTES_v2.10.0.md").write_text("# v2.10.0\n", encoding="utf-8")
    (tmp_path / "README.md").write_text("# Overview\n", encoding="utf-8")
    (tmp_path / "CHANGELOG.md").write_text("# Changelog\n", encoding="utf-8")
    (tmp_path / "CONTRIBUTING.md").write_text("# Contributing\n", encoding="utf-8")
    # Note: SECURITY.md intentionally omitted to verify missing virtual doc is pruned

    nav = [
        {"Home": "index.md"},
        {
            "How-To Guides": [
                {"Deployment": ["02-how-to-guides/deployment/missing.md"]},
                "MISSING_DOC.md",
            ]
        },
        {
            "Explanation": [
                {"Overview": "overview.md"},
                {"Security Policy": "SECURITY-POLICY.md"},
            ]
        },
        {
            "Releases & Governance": [
                {"Changelog": "CHANGELOG.md"},
                {"Release Notes v9.9.9": "RELEASE_NOTES_v9.9.9.md"},
                {"Action Logbook": "ACTION_LOGBOOK.md"},
                {"Contributing": "CONTRIBUTING.md"},
            ]
        },
    ]

    pruned = hooks.prune_nav_for_existing_files(nav, docs_dir, tmp_path)
    all_paths = _flatten_nav_paths(pruned)

    assert "index.md" in all_paths
    assert "overview.md" in all_paths
    assert "SECURITY-POLICY.md" not in all_paths
    assert "MISSING_DOC.md" not in all_paths
    assert "02-how-to-guides/deployment/missing.md" not in all_paths
    assert "RELEASE_NOTES_v9.9.9.md" not in all_paths
    assert _find_nav_section(pruned, "How-To Guides") is None

    releases_section = _find_nav_section(pruned, "Releases & Governance")
    assert releases_section is not None
    release_paths = _flatten_nav_paths(releases_section)
    assert "CHANGELOG.md" in release_paths
    assert "ACTION_LOGBOOK.md" in release_paths
    assert "CONTRIBUTING.md" in release_paths
    for note in ("RELEASE_NOTES_v2.10.0.md", "RELEASE_NOTES_v2.0.1.md", "RELEASE_NOTES_v2.0.0.md"):
        assert note in release_paths

    assert release_paths.index("RELEASE_NOTES_v2.10.0.md") < release_paths.index("RELEASE_NOTES_v2.0.1.md")
    assert release_paths.index("RELEASE_NOTES_v2.0.1.md") < release_paths.index("RELEASE_NOTES_v2.0.0.md")


def test_mkdocs_hooks_on_config_edit_uri_and_virtual_edit_url(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
) -> None:
    """Verify on_config sets edit_uri on WORKTREE/main, clears on tags, attaches gfm_slugify, and manages edit_url."""
    hooks = _load_script_module("mkdocs_hooks", HOOKS_SCRIPT_PATH)
    docs_dir = tmp_path / "docs"
    docs_dir.mkdir()
    (docs_dir / "index.md").write_text("# Home\n", encoding="utf-8")

    monkeypatch.setenv("LMS_DOCS_REF", "WORKTREE")
    monkeypatch.setenv("LMS_DOCS_SITE_URL", "https://lms.lightpath.fr/main")
    cfg_main = _AttrDict(
        {
            "docs_dir": str(docs_dir),
            "site_url": "",
            "edit_uri": "",
            "repo_url": "https://github.com/jmrenouard/lightpath-training-portal",
            "mdx_configs": {"toc": {"permalink": True}},
            "nav": [{"Home": "index.md"}],
        }
    )
    hooks.on_config(cfg_main)
    assert cfg_main["edit_uri"] == "edit/main/docs/"
    assert cfg_main["site_url"] == "https://lms.lightpath.fr/main/"
    assert cfg_main["mdx_configs"]["toc"]["slugify"] is hooks.gfm_slugify

    class _FakeFile:
        def __init__(self, src_uri: str) -> None:
            self.src_uri = src_uri
            self.src_path = src_uri

    class _FakeFiles:
        def get_file_from_path(self, path: str) -> None:
            del path
            return None

    class _FakePage:
        def __init__(self, src_uri: str, edit_url: str | None) -> None:
            self.file = _FakeFile(src_uri)
            self.edit_url = edit_url

    page_main = _FakePage(
        "overview.md",
        "https://github.com/jmrenouard/lightpath-training-portal/edit/main/docs/overview.md",
    )
    hooks.on_page_markdown("# Overview\n", page_main, cfg_main, _FakeFiles())
    assert (
        page_main.edit_url
        == "https://github.com/jmrenouard/lightpath-training-portal/edit/main/README.md"
    )

    page_policy = _FakePage(
        "SECURITY-POLICY.md",
        "https://github.com/jmrenouard/lightpath-training-portal/edit/main/docs/SECURITY-POLICY.md",
    )
    hooks.on_page_markdown("# Security\n", page_policy, cfg_main, _FakeFiles())
    assert (
        page_policy.edit_url
        == "https://github.com/jmrenouard/lightpath-training-portal/edit/main/SECURITY.md"
    )

    monkeypatch.setenv("LMS_DOCS_REF", "v2.0.0")
    cfg_tag = _AttrDict(
        {
            "docs_dir": str(docs_dir),
            "site_url": "",
            "edit_uri": "edit/main/docs/",
            "repo_url": "https://github.com/jmrenouard/lightpath-training-portal",
            "mdx_configs": {"toc": {}},
            "nav": [],
        }
    )
    hooks.on_config(cfg_tag)
    assert cfg_tag["edit_uri"] == ""

    page_tag = _FakePage(
        "overview.md",
        "https://github.com/jmrenouard/lightpath-training-portal/edit/main/docs/overview.md",
    )
    hooks.on_page_markdown("# Overview\n", page_tag, cfg_tag, _FakeFiles())
    assert not page_tag.edit_url


def test_mkdocs_hooks_importable_without_mkdocs_or_pathspec(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
) -> None:
    """Verify scripts/mkdocs_hooks.py imports and runs helper/hook functions without mkdocs or pathspec installed."""
    for blocked in (
        "mkdocs",
        "mkdocs.structure",
        "mkdocs.structure.files",
        "pathspec",
        "pathspec.gitignore",
    ):
        monkeypatch.setitem(sys.modules, blocked, None)

    try:
        hooks = _load_script_module("mkdocs_hooks_no_deps", HOOKS_SCRIPT_PATH)
        assert "!!! note" in hooks.convert_github_alerts("> [!NOTE]\n> Works without mkdocs.")
        assert hooks.gfm_slugify("Qualiopi Evidence Chain") == "qualiopi-evidence-chain"
        assert "](../overview.md)" in hooks.rewrite_relative_links(
            "[Root](../../README.md)",
            "01-tutorials/01-local-quickstart-sandbox.md",
        )
        docs_dir = tmp_path / "docs"
        docs_dir.mkdir()
        (docs_dir / "index.md").write_text("# Home\n", encoding="utf-8")
        cfg = _AttrDict(
            {
                "docs_dir": str(docs_dir),
                "site_url": "",
                "edit_uri": "",
                "mdx_configs": {"toc": {}},
                "nav": [{"Home": "index.md"}],
            }
        )
        hooks.on_config(cfg)
        assert cfg["edit_uri"] == "edit/main/docs/"
    finally:
        sys.modules.pop("mkdocs_hooks_no_deps", None)


# ==============================================================================
# 3. scripts/build_versioned_docs.py Unit & Integration Tests
# ==============================================================================


def test_build_versioned_docs_parse_semver_tag_and_manifest_ordering() -> None:
    """Verify semver parsing, numeric descending sorting, alias assignment, and empty fallback."""
    builder = _load_script_module("build_versioned_docs", BUILD_SCRIPT_PATH)

    assert builder.parse_semver_tag("v2.0.0") == (2, 0, 0)
    assert builder.parse_semver_tag("v2.0.1") == (2, 0, 1)
    assert builder.parse_semver_tag("v2.10.0") == (2, 10, 0)
    assert builder.parse_semver_tag("main") is None
    assert builder.parse_semver_tag("latest") is None
    assert builder.parse_semver_tag("v2.0") is None

    manifest = builder.build_versions_manifest(
        ["v2.0.0", "v2.2.0", "v2.10.0", "v2.0.1"],
        include_main=True,
    )
    versions = [entry["version"] for entry in manifest]

    assert versions.index("v2.10.0") < versions.index("v2.2.0")
    assert versions.index("v2.2.0") < versions.index("v2.0.1")
    assert versions.index("v2.0.1") < versions.index("v2.0.0")

    by_version = {entry["version"]: entry for entry in manifest}
    assert "latest" in by_version["v2.10.0"]["aliases"]
    assert "latest" not in by_version["v2.0.1"]["aliases"]
    assert "main" in by_version
    assert "dev" in by_version["main"]["aliases"]

    fallback_main = builder.build_versions_manifest([], include_main=True)
    assert len(fallback_main) == 1
    assert fallback_main[0]["version"] == "main"
    assert "latest" in fallback_main[0]["aliases"]
    assert "dev" in fallback_main[0]["aliases"]

    assert builder.build_versions_manifest([], include_main=False) == []


def test_build_versioned_site_rejects_protected_or_ancestor_output_dirs(tmp_path: Path) -> None:
    """Verify build_versioned_site refuses to overwrite repo root, ancestors, or protected subdirectories."""
    builder = _load_script_module("build_versioned_docs", BUILD_SCRIPT_PATH)
    repo_dir = tmp_path / "repo"
    protected_names = (".git", "docs", "src", "config", "public", "templates", "storage")
    for name in protected_names:
        (repo_dir / name).mkdir(parents=True)
    sentinel = repo_dir / "docs" / "keep.md"
    sentinel.write_text("# Keep\n", encoding="utf-8")

    forbidden_outputs = [
        repo_dir,
        tmp_path,
        repo_dir / "docs" / "nested",
        *(repo_dir / name for name in protected_names),
    ]
    for forbidden_out in forbidden_outputs:
        with pytest.raises(ValueError, match="protected"):
            builder.build_versioned_site(repo_root=repo_dir, output_dir=forbidden_out, skip_tags=True)
        assert sentinel.is_file()


def test_build_versioned_docs_render_htaccess_and_redirect_html() -> None:
    """Verify .htaccess rules (HTTPS 301, /latest/ & /dev/ 302, no Indexes, 404) and redirect HTML."""
    builder = _load_script_module("build_versioned_docs", BUILD_SCRIPT_PATH)

    htaccess = builder.render_htaccess("v2.0.0", main_version="main")
    assert "Options -Indexes" in htaccess
    assert "DirectoryIndex index.html" in htaccess
    assert "RewriteEngine On" in htaccess
    assert "RewriteBase /" in htaccess
    assert "X-Content-Type-Options" in htaccess
    assert "R=301" in htaccess
    assert "^latest(?:/(.*))?$" in htaccess
    assert "/v2.0.0/$1" in htaccess
    assert "^dev(?:/(.*))?$" in htaccess
    assert "/main/$1" in htaccess
    assert "R=302" in htaccess
    assert "ErrorDocument 404 /v2.0.0/404.html" in htaccess

    redirect_html = builder.render_redirect_html("/v2.0.0/", "LightPath Training Portal Documentation")
    assert "<!doctype html>" in redirect_html.lower()
    assert "LightPath Training Portal Documentation" in redirect_html
    assert 'http-equiv="refresh"' in redirect_html.lower()
    assert "/v2.0.0/" in redirect_html


def test_build_versioned_docs_end_to_end_worktree_and_v200(tmp_path: Path) -> None:
    """End-to-end build of WORKTREE + v2.0.0 asserting manifest, redirects, assets, and zero leaks."""
    if importlib.util.find_spec("mkdocs") is None:
        pytest.skip("mkdocs optional extra not installed in current test environment")
    if not (REPO_ROOT / ".git").exists() or shutil.which("git") is None:
        pytest.skip("Git repository metadata required for multi-version tag build test")

    tag_probe = subprocess.run(
        ["git", "-C", str(REPO_ROOT), "tag", "--list"],
        capture_output=True,
        text=True,
        check=False,
    )
    available_tags = set(tag_probe.stdout.splitlines())
    if tag_probe.returncode != 0 or "v2.0.0" not in available_tags:
        pytest.skip("Tag v2.0.0 not available in local Git clone")

    out_dir = tmp_path / "site_dist"
    proc = subprocess.run(
        [
            sys.executable,
            str(BUILD_SCRIPT_PATH),
            "--output",
            str(out_dir),
            "--base-url",
            "https://lms.lightpath.fr",
            "--main-ref",
            "WORKTREE",
            "--tags",
            "v2.0.0",
        ],
        cwd=str(REPO_ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0, (
        f"build_versioned_docs.py failed:\nSTDOUT:\n{proc.stdout}\nSTDERR:\n{proc.stderr}"
    )

    expected_files = [
        out_dir / "versions.json",
        out_dir / "index.html",
        out_dir / ".htaccess",
        out_dir / "latest" / "index.html",
        out_dir / "dev" / "index.html",
        out_dir / "main" / "index.html",
        out_dir / "main" / "stylesheets" / "extra.css",
        out_dir / "main" / "javascripts" / "katex.js",
        out_dir / "main" / "assets" / "logo.svg",
        out_dir / "main" / "assets" / "header-banner.svg",
        out_dir / "v2.0.0" / "index.html",
    ]
    for path in expected_files:
        assert path.is_file(), f"Expected built artifact missing: {path}"

    manifest = json.loads((out_dir / "versions.json").read_text(encoding="utf-8"))
    assert isinstance(manifest, list)
    versions_order = [entry["version"] for entry in manifest]
    assert versions_order.index("v2.0.0") < versions_order.index("main")

    by_version = {entry["version"]: entry for entry in manifest}
    assert "v2.0.0" in by_version
    assert "latest" in by_version["v2.0.0"]["aliases"]
    assert "main" in by_version
    assert "dev" in by_version["main"]["aliases"]

    htaccess_text = (out_dir / ".htaccess").read_text(encoding="utf-8")
    assert "/v2.0.0/$1" in htaccess_text
    assert "/main/$1" in htaccess_text

    # Verify zero broken local asset or page references across built HTML outputs
    broken_assets: list[str] = []
    for version_slug in ("main", "v2.0.0"):
        version_root = out_dir / version_slug
        for html_file in sorted(version_root.rglob("*.html")):
            html_text = html_file.read_text(encoding="utf-8", errors="ignore")
            for match in HTML_LOCAL_REF_RE.finditer(html_text):
                raw_ref = match.group(2).strip()
                if not raw_ref or raw_ref.startswith(EXTERNAL_PREFIXES):
                    continue
                target = unquote(raw_ref.split("#", 1)[0].split("?", 1)[0].strip())
                if not target:
                    continue
                if target.startswith("/"):
                    resolved = (out_dir / target.lstrip("/")).resolve()
                else:
                    resolved = (html_file.parent / target).resolve()
                exists = resolved.is_file() or (
                    resolved.is_dir() and (resolved / "index.html").is_file()
                )
                if not exists:
                    broken_assets.append(
                        f"{html_file.relative_to(out_dir).as_posix()}: {raw_ref} -> {resolved}"
                    )
    assert not broken_assets, "Broken local references in built HTML:\n" + "\n".join(broken_assets[:20])

    # Verify zero internal/ leaks and zero sensitive runtime/credential files in output bundle
    leaked_internal: list[str] = []
    leaked_sensitive: list[str] = []
    for p in out_dir.rglob("*"):
        if not p.is_file():
            continue
        rel = p.relative_to(out_dir)
        rel_posix = rel.as_posix()
        if "internal" in rel.parts[1:]:
            leaked_internal.append(rel_posix)
        lower_name = p.name.lower()
        if (
            p.suffix.lower() in {".kdbx", ".keyx", ".pid", ".sqlite", ".db"}
            or lower_name == ".env"
            or lower_name.startswith(".env.")
            or lower_name == "initial_admin_credentials.txt"
        ):
            leaked_sensitive.append(rel_posix)

    assert not leaked_internal, f"docs/internal/ files leaked into built output: {leaked_internal}"
    assert not leaked_sensitive, f"Sensitive or credential files leaked into built output: {leaked_sensitive}"


# ==============================================================================
# 4. .github/workflows/docs-publish.yml Security & Deployment Tests
# ==============================================================================


def test_docs_publish_workflow_security_and_ionos_contract() -> None:
    """Verify docs-publish.yml triggers, SHA pinning, least-privilege permissions, artifact flags, and SFTP steps."""
    assert WORKFLOW_PATH.is_file(), ".github/workflows/docs-publish.yml must exist"
    raw_yaml = WORKFLOW_PATH.read_text(encoding="utf-8")
    data = yaml.safe_load(raw_yaml)
    assert isinstance(data, dict)

    assert data.get("permissions") == {"contents": "read"}

    triggers = data.get("on") if "on" in data else data.get(True)
    assert isinstance(triggers, dict)
    assert triggers["push"]["branches"] == ["main"]
    assert triggers["pull_request"]["branches"] == ["main"]
    assert triggers["push"]["paths"] == triggers["pull_request"]["paths"]
    for required_path in (
        "docs/**",
        "mkdocs.yml",
        "README.md",
        "CHANGELOG.md",
        "requirements-docs.txt",
        ".vale.ini",
        ".markdownlint.json",
        "scripts/mkdocs_hooks.py",
        "scripts/build_versioned_docs.py",
        ".github/workflows/docs-ci.yml",
        ".github/workflows/docs-publish.yml",
        "tests/test_diataxis_documentation.py",
        "tests/test_mkdocs_publishing.py",
    ):
        assert required_path in triggers["push"]["paths"]

    assert triggers["release"] == {"types": ["published"]}
    deploy_input = triggers["workflow_dispatch"]["inputs"]["deploy"]
    assert deploy_input["type"] == "boolean"
    assert deploy_input["default"] is True

    concurrency = data.get("concurrency", {})
    assert "docs-publish-" in str(concurrency.get("group", ""))
    assert "github.ref" in str(concurrency.get("group", ""))
    assert "pull_request" in str(concurrency.get("cancel-in-progress", ""))

    jobs = data.get("jobs", {})
    assert "verify-and-build" in jobs
    assert "deploy-ionos" in jobs

    # Global supply-chain & injection checks across every job and step
    for job_name, job_cfg in jobs.items():
        if isinstance(job_cfg.get("if"), str):
            assert "secrets." not in job_cfg["if"], f"{job_name}.if must not reference secrets."
        for idx, step in enumerate(_collect_workflow_steps(job_cfg)):
            uses_val = step.get("uses")
            if isinstance(uses_val, str):
                assert "@" in uses_val, f"{job_name} step #{idx} must pin action to SHA: {uses_val}"
                sha = uses_val.split("@", 1)[1]
                assert SHA40_RE.fullmatch(sha), (
                    f"{job_name} step #{idx} must use 40-char SHA, got '{uses_val}'"
                )
            if_val = step.get("if")
            if isinstance(if_val, str):
                assert "secrets." not in if_val, f"{job_name} step #{idx} must not use secrets. in if:"
            run_val = step.get("run")
            if isinstance(run_val, str):
                assert "${{" not in run_val, (
                    f"{job_name} step #{idx} must pass GitHub expressions via env:, never ${{ in run:"
                )

    # Job 1: verify-and-build
    build_job = jobs["verify-and-build"]
    assert build_job.get("runs-on") == "ubuntu-latest"
    assert isinstance(build_job.get("timeout-minutes"), int) and build_job["timeout-minutes"] <= 15

    build_steps = _collect_workflow_steps(build_job)
    checkout_steps = [s for s in build_steps if str(s.get("uses", "")).startswith("actions/checkout@")]
    assert len(checkout_steps) == 1
    assert checkout_steps[0].get("with", {}).get("fetch-depth") == 0

    python_steps = [s for s in build_steps if str(s.get("uses", "")).startswith("actions/setup-python@")]
    assert len(python_steps) == 1
    python_with = python_steps[0].get("with", {})
    assert python_with.get("python-version") == "3.12"
    assert python_with.get("cache") == "pip"
    assert python_with.get("cache-dependency-path") == "requirements-docs.txt"

    build_run_script = "\n".join(str(s.get("run", "")) for s in build_steps)
    assert "requirements-docs.txt" in build_run_script
    assert "tests/test_diataxis_documentation.py" in build_run_script
    assert "tests/test_mkdocs_publishing.py" in build_run_script
    assert "scripts/build_versioned_docs.py" in build_run_script
    assert "origin/main" in build_run_script
    assert "WORKTREE" in build_run_script
    assert "site_dist/versions.json" in build_run_script
    assert "site_dist/.htaccess" in build_run_script

    upload_steps = [s for s in build_steps if str(s.get("uses", "")).startswith("actions/upload-artifact@")]
    assert len(upload_steps) == 1
    upload_with = upload_steps[0].get("with", {})
    assert upload_with.get("name") == "mkdocs-site-dist"
    assert upload_with.get("path") == "site_dist/"
    assert upload_with.get("include-hidden-files") is True
    assert upload_with.get("retention-days") == 7

    # Job 2: deploy-ionos
    deploy_job = jobs["deploy-ionos"]
    assert deploy_job.get("needs") == "verify-and-build"
    assert deploy_job.get("runs-on") == "ubuntu-latest"
    assert isinstance(deploy_job.get("timeout-minutes"), int) and deploy_job["timeout-minutes"] <= 15

    deploy_if = str(deploy_job.get("if", ""))
    assert "github.event_name == 'release'" in deploy_if
    assert "refs/heads/main" in deploy_if
    assert "inputs.deploy == true" in deploy_if
    assert "pull_request" not in deploy_if

    deploy_steps = _collect_workflow_steps(deploy_job)
    assert not any("actions/checkout" in str(s.get("uses", "")) for s in deploy_steps)

    download_steps = [s for s in deploy_steps if str(s.get("uses", "")).startswith("actions/download-artifact@")]
    assert len(download_steps) == 1
    assert download_steps[0].get("with", {}).get("name") == "mkdocs-site-dist"
    assert download_steps[0].get("with", {}).get("path") == "."

    sftp_steps = [s for s in deploy_steps if str(s.get("uses", "")).startswith("wlixcc/SFTP-Deploy-Action@")]
    assert len(sftp_steps) == 2, "Expected site upload step plus explicit .htaccess upload step"

    local_paths = [step.get("with", {}).get("local_path") for step in sftp_steps]
    assert "./" in local_paths
    assert ".htaccess" in local_paths

    for step in sftp_steps:
        step_with = step.get("with", {})
        assert step_with.get("sftp_only") is True
        assert step_with.get("server") == "${{ secrets.IONOS_FTP_SERVER }}"
        assert step_with.get("username") == "${{ secrets.IONOS_FTP_USERNAME }}"
        assert step_with.get("password") == "${{ secrets.IONOS_FTP_PASSWORD }}"
        assert step_with.get("port") == "${{ secrets.IONOS_FTP_PORT || '22' }}"
        assert step_with.get("remote_path") == "${{ secrets.IONOS_REMOTE_PATH }}/"

    deploy_run_script = "\n".join(str(s.get("run", "")) for s in deploy_steps)
    assert ".htaccess" in deploy_run_script
    assert "versions.json" in deploy_run_script
    assert "https://lms.lightpath.fr" in deploy_run_script

    for unsafe_pattern in (
        "chmod 777",
        "chmod -R 777",
        "chmod a+rwx",
        "sudo",
        "eval ",
        "curl -s | bash",
        "wget -O- | sh",
    ):
        assert unsafe_pattern not in raw_yaml, f"Unsafe shell pattern found in workflow: {unsafe_pattern}"


# ==============================================================================
# 5. Requirements, .gitignore, and Portal Link Consistency
# ==============================================================================


def test_gitignore_excludes_mkdocs_build_artifacts() -> None:
    """Verify .gitignore excludes /site/ and /site_dist/ and requirements-docs.txt pins MkDocs Material."""
    gitignore_lines = {
        line.strip()
        for line in GITIGNORE_PATH.read_text(encoding="utf-8").splitlines()
        if line.strip() and not line.strip().startswith("#")
    }
    assert "/site/" in gitignore_lines or "site/" in gitignore_lines, ".gitignore must exclude /site/"
    assert "/site_dist/" in gitignore_lines or "site_dist/" in gitignore_lines, (
        ".gitignore must exclude /site_dist/"
    )
    assert "/.pytest_cache/" in gitignore_lines or ".pytest_cache/" in gitignore_lines
    assert "/__pycache__/" in gitignore_lines or "__pycache__/" in gitignore_lines

    assert REQUIREMENTS_DOCS_PATH.is_file(), "requirements-docs.txt must exist"
    reqs = REQUIREMENTS_DOCS_PATH.read_text(encoding="utf-8")
    assert "mkdocs>=" in reqs
    assert "mkdocs-material>=" in reqs
    assert "pymdown-extensions>=" in reqs

    assert "https://lms.lightpath.fr" in (REPO_ROOT / "README.md").read_text(encoding="utf-8")
