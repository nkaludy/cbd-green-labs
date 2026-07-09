"""
[DOC-P57] Marks modules/ as a Python package.

Required so `from modules import cleaner, ...` works on every Python
version this repo's clones might meet — implicit namespace packages
exist since 3.3, but an explicit __init__.py also makes the intent
obvious to anyone browsing the folder.
"""
