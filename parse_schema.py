import re

schema = open('database/schema/mysql-schema.sql', 'r').read()

tables = re.findall(r'CREATE TABLE `([^`]+)` \((.*?)\) ENGINE=', schema, re.DOTALL)

for table_name, definition in tables:
    fks = re.findall(r'CONSTRAINT `[^`]+` FOREIGN KEY \(`([^`]+)`\)', definition)
    keys = re.findall(r'KEY `[^`]+` \(`([^`]+)`(?:,.*?)?\)', definition) + \
           re.findall(r'PRIMARY KEY \(`([^`]+)`(?:,.*?)?\)', definition) + \
           re.findall(r'UNIQUE KEY `[^`]+` \(`([^`]+)`(?:,.*?)?\)', definition)

    # Clean up keys (sometimes they have length or other modifiers like `id` ASC)
    clean_keys = []
    for key in keys:
        clean_keys.append(key.strip())

    for fk in fks:
        if fk not in clean_keys:
            print(f"Table {table_name} missing index for {fk}")
