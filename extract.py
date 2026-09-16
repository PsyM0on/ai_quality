import zipfile, xml.etree.ElementTree as ET
docx=zipfile.ZipFile(r'C:\Users\fangw\Downloads\AI_Capstone_2.0_051654.docx')
tree=ET.fromstring(docx.read('word/document.xml'))
ns={'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
text=[''.join(node.text for node in p.findall('.//w:t', namespaces=ns) if node.text) for p in tree.findall('.//w:p', namespaces=ns)]
with open('scratch_doc.txt', 'w', encoding='utf-8') as f:
    f.write('\n'.join(t for t in text if t))
