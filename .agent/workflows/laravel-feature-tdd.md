---
description: Implement a feature using Test Driven Development
---

steps:
  - name: "Plan"
    instruction: "Analyze the user request. Identify which Models, Controllers, and Routes are needed. Create a step-by-step plan."
  
  - name: "Create Test"
    instruction: "Create a PEST Feature test file using `artisan make:test --pest`. Write the test cases for the happy path AND failure paths. Run the test to confirm it fails (Red)."
  
  - name: "Implement"
    instruction: "Write the minimal code necessary in the Controller/Model/Service to make the test pass. Use standard Laravel 12 conventions."
  
  - name: "Verify"
    instruction: "Run the specific test file again using `sail artisan test --filter=...`. If it fails, fix the code and retry until green."
  
  - name: "Refactor"
    instruction: "Now that the test passes, run `pint` to format the code and run `larastan` to check for type errors."

Workflow 2 : Le "Bug Fix Protocol" (Debugging)

Ce workflow empêche l'IA de modifier du code au hasard sans avoir compris le problème.
YAML

name: "Laravel Bug Fix"
description: "Diagnose and fix a bug systematically"
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