{
  "suite_name": "Generated Workspace Verification Suite",
  "test_code_raw": "from pathlib import Path\n\ndef test_workspace_has_entrypoint():\n    files = [p for p in Path('.').rglob('*') if p.is_file() and '.git' not in p.parts]\n    assert files, 'workspace has no implementation files'\n    assert any(p.suffix in ('.py', '.html', '.js', '.ts', '.vue') for p in files)\n\ndef test_entrypoint_is_not_empty():\n    candidates = [p for p in Path('.').rglob('*') if p.is_file() and p.suffix in ('.html', '.py', '.js')]\n    assert candidates, 'no supported source files found'\n    assert any(p.stat().st_size > 0 for p in candidates)\n",
  "test_cases": [
    {
      "title": "test_workspace_has_entrypoint",
      "description": "Verifies generated source files exist",
      "expected_result": "At least one implementation file present"
    },
    {
      "title": "test_entrypoint_is_not_empty",
      "description": "Verifies implementation files contain content",
      "expected_result": "Source files are non-empty"
    }
  ]
}