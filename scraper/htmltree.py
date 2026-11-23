"""Minimal HTML tree builder on top of html.parser (stdlib only).

Builds a small element tree with parent pointers and simple query
helpers (by tag, by class substring, by attribute). It mirrors the
matching the PHP adapter does (substring match on the class
attribute), so selectors verified against lib/adapters/madara.php
behave the same here.
"""
from html.parser import HTMLParser

VOID = {
    "area", "base", "br", "col", "embed", "hr", "img", "input",
    "link", "meta", "param", "source", "track", "wbr",
}
# When one of these start tags arrives, an open element of the same
# kind is implicitly closed (how browsers treat malformed markup).
AUTOCLOSE = {
    "a": {"a"},
    "li": {"li"},
    "p": {"p"},
    "tr": {"tr"},
    "td": {"td", "th"},
    "th": {"td", "th"},
    "option": {"option"},
}
# Tags whose text content is code, not page text.
SKIP_TEXT = {"script", "style"}


class Node:
    __slots__ = ("tag", "attrs", "children", "parent", "text")

    def __init__(self, tag, attrs, parent=None):
        self.tag = tag
        self.attrs = dict(attrs)
        self.children = []
        self.parent = parent
        self.text = ""

    def cls(self):
        return self.attrs.get("class", "")

    def get(self, name, default=""):
        return self.attrs.get(name, default)

    def text_content(self):
        parts = [self.text]
        for child in self.children:
            parts.append(child.text_content())
        return "".join(parts)


class _Builder(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.root = Node("root", {})
        self.stack = [self.root]
        self._skip = 0

    def handle_starttag(self, tag, attrs):
        tag = tag.lower()
        if tag in SKIP_TEXT:
            self._skip += 1
        if tag in VOID:
            self.stack[-1].children.append(Node(tag, attrs, self.stack[-1]))
            return
        close = AUTOCLOSE.get(tag)
        if close:
            while len(self.stack) > 1 and self.stack[-1].tag in close:
                self.stack.pop()
        node = Node(tag, attrs, self.stack[-1])
        self.stack[-1].children.append(node)
        self.stack.append(node)

    def handle_startendtag(self, tag, attrs):
        tag = tag.lower()
        self.stack[-1].children.append(Node(tag, attrs, self.stack[-1]))

    def handle_endtag(self, tag):
        tag = tag.lower()
        if tag in SKIP_TEXT:
            self._skip = max(0, self._skip - 1)
        for i in range(len(self.stack) - 1, 0, -1):
            if self.stack[i].tag == tag:
                del self.stack[i:]
                return

    def handle_data(self, data):
        if self._skip:
            return
        if data:
            self.stack[-1].text += data


def parse(html):
    """Parse HTML into a tree. Returns the root Node."""
    builder = _Builder()
    builder.feed(html)
    builder.close()
    return builder.root


def text(node):
    """Normalized visible text of a node (whitespace collapsed)."""
    if node is None:
        return ""
    return " ".join(node.text_content().split())


def find_all(node, tag=None, cls=None, attr=None, value=None):
    """All descendants matching tag and/or class substring and/or
    attribute (exact value when given). Iterative, so deep pages are
    safe."""
    out = []
    stack = [node]
    while stack:
        current = stack.pop()
        if (tag is None or current.tag == tag) and (
            cls is None or cls in current.cls()
        ) and (
            attr is None or (
                attr in current.attrs
                and (value is None or current.attrs[attr] == value)
            )
        ):
            out.append(current)
        stack.extend(reversed(current.children))
    return out


def find_first(node, tag=None, cls=None, attr=None, value=None):
    found = find_all(node, tag=tag, cls=cls, attr=attr, value=value)
    return found[0] if found else None


def img_src(node):
    """Image URL from an <img>: lazy-loaded images keep the real URL
    in data-src while src is a placeholder. Mirrors the PHP adapter."""
    if node is None:
        return ""
    src = node.get("data-src") or node.get("data-lazy-src") or node.get("src")
    if "loading" in src and src.lower().endswith(".svg"):
        return ""
    return src.strip()
