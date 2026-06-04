#!/usr/bin/env python3
"""A small command-line inventory management tool.

The tool stores inventory records in a JSON file and provides commands to add,
update, remove, list, search, and summarize stock items.
"""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import asdict, dataclass
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Iterable

DEFAULT_STORAGE = Path("inventory.json")


class InventoryError(Exception):
    """Raised when an inventory operation cannot be completed."""


@dataclass(slots=True)
class Item:
    """Represents a single inventory item."""

    sku: str
    name: str
    quantity: int = 0
    price: Decimal = Decimal("0.00")

    @classmethod
    def from_dict(cls, data: dict[str, object]) -> "Item":
        try:
            sku = str(data["sku"]).strip()
            name = str(data["name"]).strip()
            quantity = int(data.get("quantity", 0))
            price = parse_money(data.get("price", "0.00"))
        except (KeyError, TypeError, ValueError, InvalidOperation) as exc:
            raise InventoryError(f"Invalid item data: {data!r}") from exc

        validate_item_fields(sku, name, quantity, price)
        return cls(sku=sku, name=name, quantity=quantity, price=price)

    def to_dict(self) -> dict[str, object]:
        data = asdict(self)
        data["price"] = money_to_str(self.price)
        return data

    @property
    def stock_value(self) -> Decimal:
        return self.price * self.quantity


class Inventory:
    """Inventory collection with JSON-file persistence."""

    def __init__(self, storage_path: Path = DEFAULT_STORAGE) -> None:
        self.storage_path = storage_path
        self._items: dict[str, Item] = {}

    @classmethod
    def load(cls, storage_path: Path = DEFAULT_STORAGE) -> "Inventory":
        inventory = cls(storage_path)
        if not storage_path.exists():
            return inventory

        try:
            raw_data = json.loads(storage_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            raise InventoryError(f"Could not parse JSON storage file: {storage_path}") from exc

        if not isinstance(raw_data, list):
            raise InventoryError("Inventory storage must contain a JSON list of items.")

        for raw_item in raw_data:
            if not isinstance(raw_item, dict):
                raise InventoryError(f"Invalid inventory entry: {raw_item!r}")
            item = Item.from_dict(raw_item)
            inventory._items[item.sku] = item
        return inventory

    def save(self) -> None:
        self.storage_path.parent.mkdir(parents=True, exist_ok=True)
        data = [item.to_dict() for item in self.list_items()]
        self.storage_path.write_text(
            json.dumps(data, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )

    def add_item(self, sku: str, name: str, quantity: int, price: Decimal) -> Item:
        validate_item_fields(sku, name, quantity, price)
        if sku in self._items:
            raise InventoryError(f"SKU already exists: {sku}")
        item = Item(sku=sku, name=name, quantity=quantity, price=price)
        self._items[sku] = item
        return item

    def update_item(
        self,
        sku: str,
        *,
        name: str | None = None,
        quantity: int | None = None,
        price: Decimal | None = None,
    ) -> Item:
        item = self.get_item(sku)
        next_name = item.name if name is None else name.strip()
        next_quantity = item.quantity if quantity is None else quantity
        next_price = item.price if price is None else price
        validate_item_fields(item.sku, next_name, next_quantity, next_price)
        item.name = next_name
        item.quantity = next_quantity
        item.price = next_price
        return item

    def remove_item(self, sku: str) -> Item:
        try:
            return self._items.pop(sku)
        except KeyError as exc:
            raise InventoryError(f"SKU not found: {sku}") from exc

    def adjust_stock(self, sku: str, delta: int) -> Item:
        item = self.get_item(sku)
        next_quantity = item.quantity + delta
        if next_quantity < 0:
            raise InventoryError(
                f"Insufficient stock for {sku}: current={item.quantity}, delta={delta}"
            )
        item.quantity = next_quantity
        return item

    def get_item(self, sku: str) -> Item:
        try:
            return self._items[sku]
        except KeyError as exc:
            raise InventoryError(f"SKU not found: {sku}") from exc

    def list_items(self) -> list[Item]:
        return sorted(self._items.values(), key=lambda item: item.sku.lower())

    def search(self, keyword: str) -> list[Item]:
        normalized = keyword.lower()
        return [
            item
            for item in self.list_items()
            if normalized in item.sku.lower() or normalized in item.name.lower()
        ]

    def total_value(self) -> Decimal:
        return sum((item.stock_value for item in self._items.values()), Decimal("0.00"))


def parse_money(value: object) -> Decimal:
    try:
        money = Decimal(str(value)).quantize(Decimal("0.01"))
    except InvalidOperation as exc:
        raise argparse.ArgumentTypeError(f"Invalid price: {value}") from exc
    if money < 0:
        raise argparse.ArgumentTypeError("Price cannot be negative.")
    return money


def parse_non_negative_int(value: str) -> int:
    try:
        parsed = int(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(f"Invalid integer: {value}") from exc
    if parsed < 0:
        raise argparse.ArgumentTypeError("Quantity cannot be negative.")
    return parsed


def validate_item_fields(sku: str, name: str, quantity: int, price: Decimal) -> None:
    if not sku.strip():
        raise InventoryError("SKU cannot be empty.")
    if not name.strip():
        raise InventoryError("Item name cannot be empty.")
    if quantity < 0:
        raise InventoryError("Quantity cannot be negative.")
    if price < 0:
        raise InventoryError("Price cannot be negative.")


def money_to_str(value: Decimal) -> str:
    return f"{value.quantize(Decimal('0.01')):.2f}"


def format_items(items: Iterable[Item]) -> str:
    rows = list(items)
    if not rows:
        return "No items found."

    headers = ("SKU", "Name", "Qty", "Price", "Value")
    table = [
        (
            item.sku,
            item.name,
            str(item.quantity),
            money_to_str(item.price),
            money_to_str(item.stock_value),
        )
        for item in rows
    ]
    widths = [
        max(len(str(row[column])) for row in (headers, *table))
        for column in range(len(headers))
    ]

    def render(row: tuple[str, ...]) -> str:
        return " | ".join(str(value).ljust(widths[index]) for index, value in enumerate(row))

    separator = "-+-".join("-" * width for width in widths)
    return "\n".join([render(headers), separator, *(render(row) for row in table)])


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Small JSON-backed inventory manager.")
    parser.add_argument(
        "--file",
        type=Path,
        default=DEFAULT_STORAGE,
        help="Path to the inventory JSON file (default: inventory.json).",
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    add_parser = subparsers.add_parser("add", help="Add a new item.")
    add_parser.add_argument("sku", help="Unique stock keeping unit.")
    add_parser.add_argument("name", help="Item name.")
    add_parser.add_argument("quantity", type=parse_non_negative_int, help="Initial quantity.")
    add_parser.add_argument("price", type=parse_money, help="Unit price.")

    update_parser = subparsers.add_parser("update", help="Update item details.")
    update_parser.add_argument("sku", help="SKU to update.")
    update_parser.add_argument("--name", help="New item name.")
    update_parser.add_argument("--quantity", type=parse_non_negative_int, help="New quantity.")
    update_parser.add_argument("--price", type=parse_money, help="New unit price.")

    remove_parser = subparsers.add_parser("remove", help="Remove an item.")
    remove_parser.add_argument("sku", help="SKU to remove.")

    adjust_parser = subparsers.add_parser("adjust", help="Increase or decrease stock.")
    adjust_parser.add_argument("sku", help="SKU to adjust.")
    adjust_parser.add_argument("delta", type=int, help="Quantity change, e.g. 5 or -2.")

    subparsers.add_parser("list", help="List all items.")

    search_parser = subparsers.add_parser("search", help="Search by SKU or name.")
    search_parser.add_argument("keyword", help="Search keyword.")

    subparsers.add_parser("summary", help="Show item count and total value.")
    return parser


def run(args: argparse.Namespace) -> str:
    inventory = Inventory.load(args.file)

    if args.command == "add":
        item = inventory.add_item(args.sku.strip(), args.name.strip(), args.quantity, args.price)
        inventory.save()
        return f"Added {item.sku}: {item.name}"

    if args.command == "update":
        item = inventory.update_item(
            args.sku,
            name=args.name,
            quantity=args.quantity,
            price=args.price,
        )
        inventory.save()
        return f"Updated {item.sku}: {item.name}"

    if args.command == "remove":
        item = inventory.remove_item(args.sku)
        inventory.save()
        return f"Removed {item.sku}: {item.name}"

    if args.command == "adjust":
        item = inventory.adjust_stock(args.sku, args.delta)
        inventory.save()
        return f"Adjusted {item.sku}: quantity={item.quantity}"

    if args.command == "list":
        return format_items(inventory.list_items())

    if args.command == "search":
        return format_items(inventory.search(args.keyword))

    if args.command == "summary":
        return (
            f"Items: {len(inventory.list_items())}\n"
            f"Total quantity: {sum(item.quantity for item in inventory.list_items())}\n"
            f"Total value: {money_to_str(inventory.total_value())}"
        )

    raise InventoryError(f"Unknown command: {args.command}")


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    try:
        output = run(args)
    except InventoryError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1
    print(output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
