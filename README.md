# Python Inventory Manager

A lightweight command-line inventory management tool backed by a local JSON file.
It is designed for small shops, prototypes, and learning projects that need a
simple way to track stock without a database server.

## Features

- Add, update, remove, list, and search inventory items.
- Increase or decrease stock quantities with validation to prevent negative stock.
- Store inventory data in a readable JSON file.
- Print a summary with item count, total quantity, and total stock value.

## Usage

Run commands with Python 3.11 or newer:

```bash
python inventory_manager.py --file inventory.json add A100 "Keyboard" 5 49.99
python inventory_manager.py --file inventory.json adjust A100 -1
python inventory_manager.py --file inventory.json list
python inventory_manager.py --file inventory.json search key
python inventory_manager.py --file inventory.json summary
```

If `--file` is omitted, the tool uses `inventory.json` in the current directory.

## Commands

| Command | Example | Description |
| --- | --- | --- |
| `add` | `add A100 "Keyboard" 5 49.99` | Add a new item with SKU, name, quantity, and unit price. |
| `update` | `update A100 --price 44.99` | Update an item's name, quantity, or unit price. |
| `remove` | `remove A100` | Delete an item by SKU. |
| `adjust` | `adjust A100 -2` | Increase or decrease an item's stock quantity. |
| `list` | `list` | Show all items sorted by SKU. |
| `search` | `search keyboard` | Search by SKU or item name. |
| `summary` | `summary` | Show total items, units, and inventory value. |

## Running tests

```bash
python -m unittest
```
