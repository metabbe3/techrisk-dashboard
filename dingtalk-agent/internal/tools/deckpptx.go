// deckpptx.go — generate_deck: native PPTX writer (a PPTX is a zip of
// OOXML). stdlib archive/zip only. Title+bullets slides — incident-report
// decks. ponytail: hand-rolled minimal writer; unioffice if richer layouts
// are ever requested.
package tools

import (
	"archive/zip"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

const (
	maxDeckSlides  = 20
	maxSlidePoints = 15
)

type deckSlide struct {
	Heading string   `json:"heading"`
	Bullets []string `json:"bullets"`
}

// parseDeckSpec turns the flat slides string (blank-line-separated blocks;
// "## Heading" or bare first line = heading, "- " lines = bullets) into
// slides. Keeps the LLM-facing schema flat — nested arrays 400 the whole
// request on strict upstreams (see reportpdf.go).
func parseDeckSpec(spec string) []deckSlide {
	var slides []deckSlide
	for _, block := range strings.Split(spec, "\n\n") {
		var s deckSlide
		for _, ln := range strings.Split(block, "\n") {
			ln = strings.TrimSpace(ln)
			switch {
			case ln == "":
			case strings.HasPrefix(ln, "#"):
				if s.Heading == "" {
					s.Heading = strings.TrimLeft(ln, "# ")
				}
			case strings.HasPrefix(ln, "- ") || strings.HasPrefix(ln, "* "):
				s.Bullets = append(s.Bullets, strings.TrimSpace(ln[2:]))
			default:
				if s.Heading == "" {
					s.Heading = ln
				} else {
					s.Bullets = append(s.Bullets, ln)
				}
			}
		}
		if s.Heading != "" || len(s.Bullets) > 0 {
			slides = append(slides, s)
		}
	}
	return slides
}

func handleGenerateDeck(dir, argsJSON string) (string, error) {
	var args struct {
		Title  string `json:"title"`
		Slides string `json:"slides"`
	}
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	slides := parseDeckSpec(args.Slides)
	if len(slides) == 0 {
		return "Error: slides are empty — compose the deck from tool results first.", nil
	}
	title := orDefaultStr(args.Title, "Technical Risk Deck")

	path, err := exportPath(dir, title, "pptx")
	if err != nil {
		return "", err
	}
	if err := renderDeck(path, title, slides); err != nil {
		return "", err
	}
	rel, _ := filepath.Rel(dir, path)
	return fmt.Sprintf("Deck created: %s — tell the user it was generated and sent to their DM. Include this marker verbatim in your reply: "+GeneratedFileMarker, rel, rel), nil
}

// renderDeck writes a PPTX: static scaffolding (master/layout/theme) + one
// slide XML per deckSlide. Text boxes are plain shapes, not placeholders, so
// they render without depending on layout inheritance.
func renderDeck(path, title string, slides []deckSlide) error {
	if len(slides) > maxDeckSlides {
		slides = slides[:maxDeckSlides]
	}

	f, err := os.Create(path)
	if err != nil {
		return err
	}
	defer f.Close()

	zw := zip.NewWriter(f)
	write := func(name, body string) error {
		w, err := zw.Create(name)
		if err != nil {
			return err
		}
		_, err = io.WriteString(w, body)
		return err
	}
	parts := []struct{ name, body string }{
		{"[Content_Types].xml", contentTypesXML(len(slides))},
		{"_rels/.rels", rootRelsXML},
		{"ppt/presentation.xml", presentationXML(len(slides))},
		{"ppt/_rels/presentation.xml.rels", presentationRelsXML(len(slides))},
		{"ppt/slideMasters/slideMaster1.xml", slideMasterXML},
		{"ppt/slideMasters/_rels/slideMaster1.xml.rels", slideMasterRelsXML},
		{"ppt/slideLayouts/slideLayout1.xml", slideLayoutXML},
		{"ppt/slideLayouts/_rels/slideLayout1.xml.rels", slideLayoutRelsXML},
		{"ppt/theme/theme1.xml", themeXML},
		{"ppt/presProps.xml", `<p:presentationPr xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>`},
		{"ppt/viewProps.xml", `<p:viewPr xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>`},
		{"ppt/tableStyles.xml", `<a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>`},
	}
	for i, s := range slides {
		parts = append(parts,
			struct{ name, body string }{fmt.Sprintf("ppt/slides/slide%d.xml", i+1), slideXML(s)},
			struct{ name, body string }{fmt.Sprintf("ppt/slides/_rels/slide%d.xml.rels", i+1), slideRelsXML},
		)
	}
	for _, p := range parts {
		if err := write(p.name, p.body); err != nil {
			_ = zw.Close()
			return err
		}
	}
	return zw.Close()
}

func xmlEsc(s string) string {
	r := strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;", `"`, "&quot;", "'", "&apos;")
	return r.Replace(s)
}

const (
	nsP = `xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"`
	nsA = `xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"`
	nsR = `xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"`
)

func contentTypesXML(slides int) string {
	var b strings.Builder
	b.WriteString(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` + "\n")
	b.WriteString(`<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">`)
	b.WriteString(`<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>`)
	b.WriteString(`<Default Extension="xml" ContentType="application/xml"/>`)
	b.WriteString(`<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>`)
	for i := 1; i <= slides; i++ {
		fmt.Fprintf(&b, `<Override PartName="/ppt/slides/slide%d.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>`, i)
	}
	b.WriteString(`<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>`)
	b.WriteString(`<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>`)
	b.WriteString(`<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>`)
	b.WriteString(`<Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>`)
	b.WriteString(`<Override PartName="/ppt/viewProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml"/>`)
	b.WriteString(`<Override PartName="/ppt/tableStyles.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml"/>`)
	b.WriteString(`</Types>`)
	return b.String()
}

const rootRelsXML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>
</Relationships>`

func presentationXML(slides int) string {
	var b strings.Builder
	fmt.Fprintf(&b, `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>`+"\n<p:presentation %s %s>", nsP, nsR)
	b.WriteString(`<sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></sldMasterIdLst>`)
	b.WriteString(`<sldIdLst>`)
	for i := 1; i <= slides; i++ {
		fmt.Fprintf(&b, `<p:sldId id="%d" r:id="rId%d"/>`, 255+i, i+1)
	}
	b.WriteString(`</sldIdLst>`)
	b.WriteString(`<sldSz cx="12192000" cy="6858000"/><notesSz cx="6858000" cy="9144000"/></p:presentation>`)
	return b.String()
}

func presentationRelsXML(slides int) string {
	var b strings.Builder
	b.WriteString(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>`)
	for i := 1; i <= slides; i++ {
		fmt.Fprintf(&b, `<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide%d.xml"/>`, i+1, i)
	}
	b.WriteString(`<Relationship Id="rId100" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>`)
	b.WriteString(`<Relationship Id="rId101" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/viewProps" Target="viewProps.xml"/>`)
	b.WriteString(`<Relationship Id="rId102" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>`)
	b.WriteString(`<Relationship Id="rId103" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tableStyles" Target="tableStyles.xml"/>`)
	b.WriteString(`</Relationships>`)
	return b.String()
}

const slideMasterRelsXML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>
</Relationships>`

const slideLayoutRelsXML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
</Relationships>`

const slideRelsXML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
</Relationships>`

const slideMasterXML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<p:cSld><p:spTree>
<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>
<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title Placeholder"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:spPr/></p:sp>
<p:sp><p:nvSpPr><p:cNvPr id="3" name="Body Placeholder"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/></p:sp>
</p:spTree></p:cSld>
<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>
<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>
</p:sldMaster>`

const slideLayoutXML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldLayout xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" type="blank" preserve="1">
<p:cSld name="Blank"><p:spTree>
<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>
</p:spTree></p:cSld>
<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>
</p:sldLayout>`

// Minimal theme: clrScheme + fontScheme + fmtScheme with the required lists.
// ponytail: fixed Office palette; restyle via theme XML if branding lands.
const themeXML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Risk">
<a:themeElements>
<a:clrScheme name="Risk"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="1E293B"/></a:dk2><a:lt2><a:srgbClr val="F1F5F9"/></a:lt2><a:accent1><a:srgbClr val="4F46E5"/></a:accent1><a:accent2><a:srgbClr val="0EA5E9"/></a:accent2><a:accent3><a:srgbClr val="10B981"/></a:accent3><a:accent4><a:srgbClr val="F59E0B"/></a:accent4><a:accent5><a:srgbClr val="EF4444"/></a:accent5><a:accent6><a:srgbClr val="8B5CF6"/></a:accent6><a:hlink><a:srgbClr val="4F46E5"/></a:hlink><a:folHlink><a:srgbClr val="9333EA"/></a:folHlink></a:clrScheme>
<a:fontScheme name="Risk"><a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>
<a:fmtScheme name="Risk">
<a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>
<a:lnStyleLst><a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>
<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>
<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>
</a:fmtScheme>
</a:themeElements>
</a:theme>`

// slideXML renders one slide: heading (top, bold) + bullets (below). Plain
// text-box shapes with explicit styling — no placeholder inheritance.
func slideXML(s deckSlide) string {
	var b strings.Builder
	fmt.Fprintf(&b, `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>`+"\n<p:sld %s %s %s>\n<p:cSld><p:spTree>\n", nsP, nsA, nsR)
	b.WriteString(`<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>\n`)

	// Heading
	fmt.Fprintf(&b, `<p:sp><p:nvSpPr><p:cNvPr id="2" name="Heading"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>`)
	b.WriteString(`<p:spPr><a:xfrm><a:off x="685800" y="457200"/><a:ext cx="10820400" cy="1371600"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>`)
	b.WriteString(`<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:pPr algn="l"/><a:r><a:rPr lang="en-US" sz="3200" b="1"><a:solidFill><a:srgbClr val="1E293B"/></a:solidFill><a:latin typeface="Calibri Light"/></a:rPr><a:t>`)
	b.WriteString(xmlEsc(s.Heading))
	b.WriteString(`</a:t></a:r></a:p></p:txBody></p:sp>\n`)

	// Accent rule
	b.WriteString(`<p:sp><p:nvSpPr><p:cNvPr id="3" name="Rule"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>`)
	b.WriteString(`<p:spPr><a:xfrm><a:off x="685800" y="1828800"/><a:ext cx="2286000" cy="38100"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:solidFill><a:srgbClr val="4F46E5"/></a:solidFill><a:ln><a:noFill/></a:ln></p:spPr>`)
	b.WriteString(`<p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody></p:sp>\n`)

	// Bullets
	if len(s.Bullets) > maxSlidePoints {
		s.Bullets = s.Bullets[:maxSlidePoints]
	}
	if len(s.Bullets) > 0 {
		b.WriteString(`<p:sp><p:nvSpPr><p:cNvPr id="4" name="Body"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>`)
		b.WriteString(`<p:spPr><a:xfrm><a:off x="685800" y="2057400"/><a:ext cx="10820400" cy="4114800"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>`)
		b.WriteString(`<p:txBody><a:bodyPr/><a:lstStyle/>`)
		for _, pt := range s.Bullets {
			b.WriteString(`<a:p><a:pPr marL="285750" indent="-285750"><a:buClr><a:srgbClr val="4F46E5"/></a:buClr><a:buFont typeface="Arial"/><a:buChar char="•"/></a:pPr>`)
			b.WriteString(`<a:r><a:rPr lang="en-US" sz="1600"><a:solidFill><a:srgbClr val="334155"/></a:solidFill></a:rPr><a:t>`)
			b.WriteString(xmlEsc(pt))
			b.WriteString(`</a:t></a:r></a:p>`)
		}
		b.WriteString(`</p:txBody></p:sp>\n`)
	}

	b.WriteString(`</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>`)
	return b.String()
}
