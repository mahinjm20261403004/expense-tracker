from pathlib import Path

def test_workspace_has_entrypoint():
    files = [p for p in Path('.').rglob('*') if p.is_file() and '.git' not in p.parts]
    assert files, 'workspace has no implementation files'
    assert any(p.suffix in ('.py', '.html', '.js', '.ts', '.vue') for p in files)

def test_entrypoint_is_not_empty():
    candidates = [p for p in Path('.').rglob('*') if p.is_file() and p.suffix in ('.html', '.py', '.js')]
    assert candidates, 'no supported source files found'
    assert any(p.stat().st_size > 0 for p in candidates)
