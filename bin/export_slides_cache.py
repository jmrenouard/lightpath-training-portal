#!/usr/bin/env python3
"""
Export official Studio 16:9 slide decks (PDF + PPTX metadata/notes) for all 34 modules
into public/assets/slides_cache/<module_id>/slide_001.png..slide_NNN.png + manifest.json.
"""
import json
import time
from pathlib import Path
import fitz  # PyMuPDF
from pptx import Presentation

ROOT = Path(__file__).resolve().parent.parent
SRC_SLIDES = ROOT.parent / "training_postgresql" / "03_supports_de_cours"
OUT_ROOT = ROOT / "public" / "assets" / "slides_cache"


def export_all_decks() -> None:
    OUT_ROOT.mkdir(parents=True, exist_ok=True)
    t0 = time.time()
    total_slides = 0
    total_decks = 0

    for pdf_path in sorted(SRC_SLIDES.glob("*_slides.pdf")):
        mod_id = pdf_path.name.replace("_slides.pdf", "")
        pptx_path = SRC_SLIDES / f"{mod_id}.pptx"
        mod_out = OUT_ROOT / mod_id
        mod_out.mkdir(parents=True, exist_ok=True)

        pptx_meta = []
        if pptx_path.exists():
            prs = Presentation(str(pptx_path))
            for idx, slide in enumerate(prs.slides):
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
                notes_txt = ""
                if slide.has_notes_slide and slide.notes_slide.notes_text_frame:
                    notes_txt = slide.notes_slide.notes_text_frame.text.strip()

                if idx == 0:
                    sec = "Couverture & Objectifs"
                    title = lines[0] if lines else f"Diapositive {idx + 1}"
                else:
                    sec = lines[0] if len(lines) >= 1 else f"Section {idx + 1}"
                    title = lines[1] if len(lines) >= 2 else sec
                    if len(title) > 95:
                        title = title[:92] + "..."

                body_preview = " • ".join(lines[2:6])[:240] if len(lines) > 2 else ""
                st_lower = (sec + " " + title).lower()
                stype = "concept"
                if idx == 0:
                    stype = "title"
                elif "objectif" in st_lower or "plan" in st_lower or "sommaire" in st_lower:
                    stype = "objectives"
                elif "quiz" in st_lower or "qcm" in st_lower:
                    stype = "quiz"
                elif "atelier" in st_lower or "lab" in st_lower:
                    stype = "lab"
                elif "synthèse" in st_lower or "points clés" in st_lower:
                    stype = "summary"

                pptx_meta.append(
                    {
                        "section": sec[:80],
                        "title": title[:110],
                        "notes": notes_txt,
                        "body_preview": body_preview,
                        "type": stype,
                    }
                )

        doc = fitz.open(str(pdf_path))
        n_pages = len(doc)
        slides_manifest = []
        for idx, page in enumerate(doc):
            slide_num = idx + 1
            img_name = f"slide_{slide_num:03d}.png"
            img_path = mod_out / img_name
            if not img_path.exists():
                pix = page.get_pixmap(dpi=135, alpha=False)
                pix.save(str(img_path))
            meta = (
                pptx_meta[idx]
                if idx < len(pptx_meta)
                else {
                    "section": f"Section {slide_num}",
                    "title": f"Diapositive {slide_num} / {n_pages}",
                    "notes": "",
                    "body_preview": "",
                    "type": "concept",
                }
            )
            slides_manifest.append(
                {
                    "number": slide_num,
                    "total": n_pages,
                    "type": meta["type"],
                    "section": meta["section"],
                    "title": meta["title"],
                    "image_url": f"assets/slides_cache/{mod_id}/{img_name}",
                    "notes": meta["notes"],
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
        f"Exported {total_decks} decks ({total_slides} slides) in {time.time() - t0:.2f}s"
    )


if __name__ == "__main__":
    export_all_decks()
