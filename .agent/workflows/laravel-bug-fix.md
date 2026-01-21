---
description: Diagnose and fix a bug systematically
---

steps:
  - name: "Gather Context"
    instruction: "Use `browser-logs` or `docker` logs to find the exact error message. Use `grepai` to find the relevant code files."
  
  - name: "Reproduce"
    instruction: "Write a reproduction test case in PEST that fails because of this bug. Do NOT touch the application code yet."
  
  - name: "Analyze"
    instruction: "Explain WHY the bug happens based on the failed test and the code analysis."
  
  - name: "Fix"
    instruction: "Apply the fix to the application code."
  
  - name: "Confirm"
    instruction: "Run the reproduction test to ensure it now passes. Then run the full test suite to ensure no regression."