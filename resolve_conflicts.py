import sys
import re

def resolve(filepath, strategy='ours'):
    with open(filepath, 'r') as f:
        lines = f.readlines()

    output = []
    inside_conflict = False
    inside_ours = False
    inside_theirs = False

    # Markers can be:
    # <<<<<<< HEAD or <<<<<<< ours
    # =======
    # >>>>>>> branch or >>>>>>> theirs

    for line in lines:
        if line.startswith('<<<<<<<'):
            inside_conflict = True
            inside_ours = True
            inside_theirs = False
            continue

        if line.startswith('======='):
            inside_ours = False
            inside_theirs = True
            continue

        if line.startswith('>>>>>>>'):
            inside_conflict = False
            inside_ours = False
            inside_theirs = False
            continue

        if inside_conflict:
            if strategy == 'ours' and inside_ours:
                output.append(line)
            elif strategy == 'theirs' and inside_theirs:
                output.append(line)
        else:
            output.append(line)

    with open(filepath, 'w') as f:
        f.writelines(output)

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python3 resolve_conflicts.py <file> <strategy>")
        sys.exit(1)

    resolve(sys.argv[1], sys.argv[2])
