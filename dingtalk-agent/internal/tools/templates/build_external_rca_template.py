#!/usr/bin/env python3
"""One-shot builder for external-rca.docx (embedded by externalrca.go).

Usage: python3 build_external_rca_template.py <sample.docx> external-rca.docx

Takes the owner's real incident report as the sample, injects single-run
placeholders, and keeps every other zip part byte-identical so styles, logo,
numbering and footer survive untouched. Re-run whenever the owner refreshes
the house format.
"""
import re
import sys
import zipfile

RUN = re.compile(r"<w:r(?:\s[^>]*)?>.*?</w:r>", re.S)
T = re.compile(r"<w:t[^>]*>([^<]*)</w:t>")
PARA = re.compile(r"<w:p(?:\s[^>]*)?>.*?</w:p>", re.S)
PARAID = re.compile(r'\s+w14:(?:paraId|textId)="[^"]*"')


def para_text(p):
    return "".join(T.findall(p))


def collapse_runs(p, replacement, justify=False):
    """Replace ALL runs of a paragraph with one run carrying the first run's rPr.
    justify=True additionally forces <w:jc w:val="both"/> + 1.5 line spacing —
    the sample mixed Aptos/Calibri and left-aligned its root cause."""
    m = RUN.search(p)
    assert m, "paragraph has no runs"
    rpr_m = re.search(r"<w:rPr>.*?</w:rPr>", m.group(), re.S)
    rpr = rpr_m.group() if rpr_m else ""
    head = p[: m.start()]
    if justify:
        head = tidy_ppr(head)
    return f'{head}<w:r>{rpr}<w:t xml:space="preserve">{replacement}</w:t></w:r></w:p>'


def collapse_body_runs(p, replacement):
    """Collapse only the non-whitespace runs (the sample indented the root
    cause with literal bold spaces — dropped; tidy = flush justified)."""
    runs = list(RUN.finditer(p))
    body = [m for m in runs if para_text(m.group()).strip()]
    assert body, "no body runs"
    rpr_m = re.search(r"<w:rPr>.*?</w:rPr>", body[0].group(), re.S)
    rpr = rpr_m.group() if rpr_m else ""
    head = p[: body[0].start()]
    head = tidy_ppr(head)
    return f'{head}<w:r>{rpr}<w:t xml:space="preserve">{replacement}</w:t></w:r></w:p>'


def tidy_ppr(head):
    """Add <w:spacing w:line="360" w:lineRule="auto"/> + <w:jc w:val="both"/>
    to the paragraph's pPr (schema order: spacing < jc < rPr). The head spans
    from <w:p ...> up to the first run, so pPr lives inside it."""
    m = re.search(r"<w:pPr>.*?</w:pPr>", head, re.S)
    add = '<w:spacing w:line="360" w:lineRule="auto"/><w:jc w:val="both"/>'
    if not m:
        return re.sub(r"^(<w:p(?:\s[^>]*)?>)", r"\1<w:pPr>" + add + "</w:pPr>", head, count=1)
    block = m.group()
    if "<w:spacing" not in block:
        anchor = re.search(r"<w:jc|<w:rPr", block)
        at = anchor.start() if anchor else block.index("</w:pPr>")
        block = block[:at] + '<w:spacing w:line="360" w:lineRule="auto"/>' + block[at:]
    if "<w:jc" not in block:
        anchor = re.search(r"<w:rPr", block)
        at = anchor.start() if anchor else block.index("</w:pPr>")
        block = block[:at] + '<w:jc w:val="both"/>' + block[at:]
    return head.replace(m.group(), block)


def unify_fonts(doc):
    """One font everywhere: the sample mixed the theme font (Aptos) with
    explicit Calibri — force Calibri on every run/paragraph-mark rPr."""
    font = '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
    doc = re.sub(r"<w:rFonts [^>]*/>", font, doc)
    doc = re.sub(r"<w:rPr>(?!<w:rFonts)", "<w:rPr>" + font, doc)
    return doc


def main(src, dst):
    with zipfile.ZipFile(src) as z:
        parts = {n: z.read(n) for n in z.namelist()}
        order = z.namelist()

    doc = parts["word/document.xml"].decode("utf-8")
    paras = [m.group() for m in PARA.finditer(doc)]

    # sanity: the paragraphs we touch are the ones we think they are
    assert para_text(paras[2]).startswith("06 Nov"), para_text(paras[2])
    assert para_text(paras[8]).startswith("Incident Report for"), para_text(paras[8])
    assert para_text(paras[10]).startswith("On 05 Nov"), para_text(paras[10])
    assert para_text(paras[12]).startswith("Abnormal traffic"), para_text(paras[12])
    assert para_text(paras[14]).strip().startswith("Abnormal traffic"), para_text(paras[14])

    # chronology table from the ORIGINAL doc: keep row 0 as prototype, drop rows 1-8
    chron = re.findall(r"<w:tbl>.*?</w:tbl>", doc, re.S)[1]
    rows = list(re.finditer(r"<w:tr(?:\s[^>]*)?>.*?</w:tr>", chron, re.S))
    assert len(rows) == 9, f"expected 9 chronology rows, got {len(rows)}"
    r0 = rows[0].group()
    ps = list(PARA.finditer(r0))
    assert len(ps) == 3, f"row 0 should have 3 paras (date, time, event), got {len(ps)}"
    row_new = r0.replace(ps[0].group(), collapse_runs(ps[0].group(), "{{ROW_DATE}}"))
    ps = list(PARA.finditer(row_new))
    row_new = row_new.replace(ps[1].group(), collapse_runs(ps[1].group(), "{{ROW_TIME}}"))
    ps = list(PARA.finditer(row_new))
    row_new = row_new.replace(ps[2].group(), collapse_runs(ps[2].group(), "{{ROW_EVENT}}"))
    row_new = PARAID.sub("", row_new)
    chron_new = chron
    for r in rows[1:]:
        chron_new = chron_new.replace(r.group(), "")
    chron_new = chron_new.replace(rows[0].group(), row_new)
    doc = doc.replace(chron, chron_new)

    # header date + title (recompute paras on the ORIGINAL list — strings unique)
    doc = doc.replace(paras[2], collapse_runs(paras[2], "{{REPORT_DATE}}"))
    doc = doc.replace(paras[8], collapse_runs(paras[8], "Incident Report for {{INCIDENT_DATE}} Incident"))

    # body paragraphs — justified + 1.5 spacing; the root cause's literal
    # 11-space indent is dropped (tidy = flush, justified, same font)
    doc = doc.replace(paras[10], collapse_runs(paras[10], "{{SUMMARY}}", justify=True))
    doc = doc.replace(paras[12], collapse_runs(paras[12], "{{PROBLEM}}", justify=True))
    doc = doc.replace(paras[14], collapse_body_runs(paras[14], "{{ROOT_CAUSE}}"))

    # corrective actions: keep para 16 as the {{ACTION}} prototype, drop 17-19
    proto = PARAID.sub("", collapse_runs(paras[16], "{{ACTION}}"))
    doc = doc.replace(paras[16], proto)
    for i in (17, 18, 19):
        doc = doc.replace(paras[i], "")

    # font unification is the LAST document.xml step — it must also normalize
    # the runs the collapse steps just wrote
    doc = unify_fonts(doc)

    parts["word/document.xml"] = doc.encode("utf-8")

    # core.xml: strip personal creator + title
    core = parts["docProps/core.xml"].decode("utf-8")
    core = re.sub(r"<dc:creator>[^<]*</dc:creator>", "<dc:creator></dc:creator>", core)
    core = re.sub(r"<dc:title>[^<]*</dc:title>", "<dc:title></dc:title>", core)
    parts["docProps/core.xml"] = core.encode("utf-8")

    with zipfile.ZipFile(dst, "w", zipfile.ZIP_DEFLATED) as z:
        for name in order:
            z.writestr(name, parts[name])

    final = parts["word/document.xml"].decode("utf-8")
    for ph in ("{{REPORT_DATE}}", "{{INCIDENT_DATE}}", "{{SUMMARY}}", "{{PROBLEM}}",
               "{{ROOT_CAUSE}}", "{{ACTION}}", "{{ROW_DATE}}", "{{ROW_TIME}}", "{{ROW_EVENT}}"):
        n = final.count(ph)
        print(f"{ph}: {n}")
        assert n == 1, ph
    rows_n = len(re.findall(r"<w:tr(?:\s[^>]*)?>", re.findall(r"<w:tbl>.*?</w:tbl>", final, re.S)[1]))
    print("chronology rows:", rows_n)
    assert rows_n == 1
    print("OK ->", dst)


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
