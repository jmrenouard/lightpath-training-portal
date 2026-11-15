#!/usr/bin/env python3
"""
Export official Studio 16:9 slide decks (PDF + PPTX metadata/notes) for all modules
into public/assets/slides_cache/<module_id>/slide_001.png..slide_NNN.png + manifest.json.

Guarantees 100% 1:1 alignment between each rendered slide_NNN.png and its manifest entry
by extracting section, title, and body preview directly from each PDF page (the exact source
of slide_NNN.png) and matching PPTX speaker notes by normalized slide title.
"""
import json
import re
import time
from pathlib import Path
import pymupdf
from pptx import Presentation

ROOT = Path(__file__).resolve().parent.parent
SRC_SLIDES = ROOT.parent / "training_postgresql" / "03_supports_de_cours"
OUT_ROOT = ROOT / "public" / "assets" / "slides_cache"


def normalize_key(text: str) -> str:
    """Normalize slide titles to alphanumeric lowercase keys for PPTX notes matching."""
    return re.sub(r"[^a-z0-9]+", "", text.lower())


def is_ignored_line(text: str, x0: float, y0: float) -> bool:
    """Filter out slide chrome headers, footers, and brand watermarks during extraction."""
    t = text.strip()
    if not t:
        return True
    if y0 > 488:
        return True
    if x0 > 680 and y0 < 95 and t in ("LightPath", "PostgreSQL", "Postgre", "SQL"):
        return True
    if (
        "Formation PostgreSQL 18 — LightPath SARL" in t
        or "Doc officielle (postgresql.org)" in t
        or re.match(r"^Diapositive\s+\d+\s*/\s*\d+$", t)
    ):
        return True
    return False


def extract_pdf_page_metadata(page: "pymupdf.Page", page_idx: int, total_pages: int) -> dict:
    """Extract section, title, slide classification, and body preview from a PDF slide page."""
    d = page.get_text("dict")
    raw_lines = []
    for block in d.get("blocks", []):
        for line in block.get("lines", []):
            spans = line.get("spans", [])
            if not spans:
                continue
            y0 = float(line["bbox"][1])
            x0 = float(line["bbox"][0])
            max_sz = max(float(s.get("size", 0.0)) for s in spans)
            txt = "".join(s.get("text", "") for s in spans).strip()
            if not is_ignored_line(txt, x0, y0):
                raw_lines.append((round(y0, 1), round(x0, 1), round(max_sz, 1), txt))

    raw_lines.sort(key=lambda item: (item[0], item[1]))

    if page_idx == 0:
        title_parts = [txt for (y0, _x0, sz, txt) in raw_lines if sz >= 24.0 and y0 < 320]
        if not title_parts:
            title_parts = [raw_lines[0][3]] if raw_lines else ["Diapositive 1"]
        title = " ".join(title_parts).strip()
        body_parts = [
            txt
            for (y0, _x0, sz, txt) in raw_lines
            if sz < 24.0 and txt != "Jean-Marie Renouard" and txt != "PostgreSQL 18"
        ]
        return {
            "section": "Couverture & Objectifs",
            "title": title[:115],
            "body_preview": " • ".join(body_parts[:4])[:260],
            "type": "title",
        }

    section_parts = []
    title_parts = []
    body_parts = []

    for y0, _x0, sz, txt in raw_lines:
        if y0 < 42.0 and sz <= 12.5:
            section_parts.append(txt)
        elif 42.0 <= y0 <= 118.0 and sz >= 14.5:
            title_parts.append(txt)
        else:
            clean_b = re.sub(r"^[•▸▪\-]+\s*", "", txt).strip()
            if clean_b:
                body_parts.append(clean_b)

    section = " ".join(section_parts).strip() if section_parts else f"Section {page_idx + 1}"
    title = " ".join(title_parts).strip()
    if not title:
        if body_parts:
            title = body_parts[0]
            body_parts = body_parts[1:]
        else:
            title = f"{section} — Diapositive {page_idx + 1} / {total_pages}"

    if len(title) > 110:
        title = title[:107] + "..."

    st_lower = (section + " " + title).lower()
    stype = "concept"
    if "objectif" in st_lower or "plan du module" in st_lower or "sommaire" in st_lower:
        stype = "objectives"
    elif "quiz" in st_lower or "qcm" in st_lower or "évaluation formative" in st_lower:
        stype = "quiz"
    elif "atelier" in st_lower or "lab " in st_lower or "travaux pratiques" in st_lower:
        stype = "lab"
    elif "synthèse" in st_lower or "points clés" in st_lower:
        stype = "summary"

    body_preview = " • ".join(body_parts[:5])[:260]
    return {
        "section": section[:80],
        "title": title,
        "body_preview": body_preview,
        "type": stype,
    }


def extract_pptx_notes_map(pptx_path: Path) -> dict[str, str]:
    """Build a lookup map of normalized slide title keys to speaker notes from a PPTX deck."""
    notes_by_key: dict[str, str] = {}
    if not pptx_path.exists():
        return notes_by_key

    prs = Presentation(str(pptx_path))
    for slide in prs.slides:
        notes_txt = ""
        if slide.has_notes_slide and slide.notes_slide.notes_text_frame:
            notes_txt = slide.notes_slide.notes_text_frame.text.strip()
        if not notes_txt:
            continue

        lines = []
        for shape in slide.shapes:
            if shape.has_text_frame:
                for p in shape.text_frame.paragraphs:
                    t = p.text.strip()
                    if (
                        t
                        and "Jean-Marie Renouard" not in t
                        and "LightPath SARL" not in t
                        and "Doc officielle (postgresql.org)" not in t
                        and not t.startswith("Diapositive ")
                        and t != "PostgreSQL 18"
                        and not t.isdigit()
                    ):
                        lines.append(t)
        for candidate in lines[:3]:
            k = normalize_key(candidate[:90])
            if len(k) >= 6 and k not in notes_by_key:
                notes_by_key[k] = notes_txt
    return notes_by_key


def export_all_decks() -> None:
    """Render PNG slides and write manifest.json for every *_slides.pdf deck found."""
    OUT_ROOT.mkdir(parents=True, exist_ok=True)
    t0 = time.time()
    total_slides = 0
    total_decks = 0

    for pdf_path in sorted(SRC_SLIDES.glob("*_slides.pdf")):
        mod_id = pdf_path.name.replace("_slides.pdf", "")
        pptx_path = SRC_SLIDES / f"{mod_id}.pptx"
        mod_out = OUT_ROOT / mod_id
        mod_out.mkdir(parents=True, exist_ok=True)

        notes_map = extract_pptx_notes_map(pptx_path)

        doc = pymupdf.open(str(pdf_path))
        n_pages = len(doc)
        slides_manifest = []
        for idx, page in enumerate(doc):
            slide_num = idx + 1
            img_name = f"slide_{slide_num:03d}.png"
            img_path = mod_out / img_name
            if not img_path.exists():
                pix = page.get_pixmap(dpi=135, alpha=False)
                pix.save(str(img_path))

            meta = extract_pdf_page_metadata(page, idx, n_pages)
            title_key = normalize_key(meta["title"][:90])
            matched_notes = notes_map.get(title_key, "")

            slides_manifest.append(
                {
                    "number": slide_num,
                    "total": n_pages,
                    "type": meta["type"],
                    "section": meta["section"],
                    "title": meta["title"],
                    "image_url": f"assets/slides_cache/{mod_id}/{img_name}",
                    "notes": matched_notes,
                    "body_preview": meta["body_preview"],
                }
            )
        doc.close()

        manifest = {
            "module_id": mod_id,
            "total_slides": n_pages,
            "slides": slides_manifest,
        }
        (mod_out / "manifest.json").write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        total_decks += 1
        total_slides += n_pages

    print(
        f"Exported {total_decks} decks ({total_slides} slides) with 1:1 PDF/PNG alignment in {time.time() - t0:.2f}s"
    )


if __name__ == "__main__":
    export_all_decks()
