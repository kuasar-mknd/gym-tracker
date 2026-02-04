import os
import re

def count_method_lines(filepath):
    with open(filepath, 'r') as f:
        lines = f.readlines()

    methods = {}
    current_method = None
    brace_count = 0
    start_line = 0

    for i, line in enumerate(lines):
        # Simple regex to find method definition
        match = re.search(r'public function (\w+)\(', line)
        if match:
            current_method = match.group(1)
            brace_count = 0
            start_line = i

        if current_method:
            brace_count += line.count('{')
            brace_count -= line.count('}')

            if brace_count == 0 and '{' in ''.join(lines[start_line:i+1]): # End of method
                methods[current_method] = i - start_line + 1
                current_method = None

    return methods

controller_dir = 'app/Http/Controllers'
results = []

for root, dirs, files in os.walk(controller_dir):
    for file in files:
        if file.endswith('.php'):
            filepath = os.path.join(root, file)
            try:
                methods = count_method_lines(filepath)
                for method, loc in methods.items():
                    results.append((file, method, loc))
            except Exception as e:
                print(f"Error parsing {file}: {e}")

results.sort(key=lambda x: x[2], reverse=True)

for file, method, loc in results[:10]:
    print(f"{file} - {method}: {loc} lines")
