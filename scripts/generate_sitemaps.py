#!/usr/bin/env python3
"""Build deterministic sitemaps from the public static HTML, without dependencies."""
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit, urljoin, unquote
import argparse
import re
import xml.etree.ElementTree as ET

SITE = 'https://vahidparsa.com'
NS = 'http://www.sitemaps.org/schemas/sitemap/0.9'
EXCLUDED = {'deploy', 'scripts', 'tests', 'node_modules', 'assets', 'dist', 'build', '__pycache__'}
ET.register_namespace('', NS)

class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.canonical = None
        self.noindex = False
        self.redirect = False
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'meta':
            if a.get('name', '').lower() in {'robots', 'googlebot', 'bingbot'}:
                self.noindex |= 'noindex' in re.split(r'[\s,]+', a.get('content', '').lower())
            self.redirect |= a.get('http-equiv', '').lower() == 'refresh'
        if tag == 'link' and 'canonical' in a.get('rel', '').lower().split():
            self.canonical = a.get('href')

def generate(root):
    root = root.resolve()
    urls = set()
    for file in sorted(root.rglob('*.html')):
        relative = file.relative_to(root)
        if any(p.startswith('.') or p in EXCLUDED for p in relative.parts):
            continue
        if file.name == '404.html':
            continue
        page = Page()
        page.feed(file.read_text(encoding='utf-8'))
        if page.noindex or page.redirect:
            continue
        path = relative.as_posix()
        if file.name == 'index.html':
            path = path[:-10]
        inferred = SITE + '/' + path
        canonical = urljoin(inferred, page.canonical) if page.canonical else inferred
        url = urlsplit(canonical)
        if url.scheme != 'https' or url.netloc != 'vahidparsa.com' or url.query or url.fragment:
            raise ValueError(f'Invalid public canonical: {relative}')
        target = root / unquote(url.path).lstrip('/')
        if url.path.endswith('/'):
            target /= 'index.html'
        if not target.is_file() or not target.resolve().is_relative_to(root):
            raise ValueError(f'Canonical has no local page: {relative}')
        # Canonical aliases are listed once, with the destination URL.
        urls.add(canonical)
    if not urls:
        raise ValueError('No indexable public pages found')
    if len(urls) > 50000:
        raise ValueError('Split URL sets before exceeding the sitemap protocol limit')
    pages = ET.Element(f'{{{NS}}}urlset')
    for url in sorted(urls):
        row = ET.SubElement(pages, f'{{{NS}}}url')
        ET.SubElement(row, f'{{{NS}}}loc').text = url
    index = ET.Element(f'{{{NS}}}sitemapindex')
    row = ET.SubElement(index, f'{{{NS}}}sitemap')
    ET.SubElement(row, f'{{{NS}}}loc').text = SITE + '/sitemap.xml'
    changed = []
    for name, tree in [('sitemap.xml', pages), ('sitemap_index.xml', index)]:
        ET.indent(tree, space='  ')
        content = ET.tostring(tree, encoding='utf-8', xml_declaration=True) + b'\n'
        destination = root / name
        if not destination.exists() or destination.read_bytes() != content:
            destination.write_bytes(content)
            changed.append(name)
    print(f'{len(urls)} indexable URLs; updated: {", ".join(changed) or "none"}')

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    generate(parser.parse_args().root)
