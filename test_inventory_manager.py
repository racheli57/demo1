from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from inventory_manager import Inventory, InventoryError, format_items, main


class InventoryTests(unittest.TestCase):
    def test_add_save_and_load_item(self) -> None:
        with tempfile.TemporaryDirectory() as tmpdir:
            storage = Path(tmpdir) / "inventory.json"
            inventory = Inventory.load(storage)
            inventory.add_item("A100", "Keyboard", 3, Decimal("49.99"))
            inventory.save()

            loaded = Inventory.load(storage)
            item = loaded.get_item("A100")

        self.assertEqual(item.name, "Keyboard")
        self.assertEqual(item.quantity, 3)
        self.assertEqual(item.price, Decimal("49.99"))
        self.assertEqual(item.stock_value, Decimal("149.97"))

    def test_duplicate_sku_is_rejected(self) -> None:
        inventory = Inventory()
        inventory.add_item("A100", "Keyboard", 3, Decimal("49.99"))

        with self.assertRaisesRegex(InventoryError, "SKU already exists"):
            inventory.add_item("A100", "Mouse", 1, Decimal("19.99"))

    def test_adjust_stock_cannot_go_below_zero(self) -> None:
        inventory = Inventory()
        inventory.add_item("A100", "Keyboard", 3, Decimal("49.99"))

        with self.assertRaisesRegex(InventoryError, "Insufficient stock"):
            inventory.adjust_stock("A100", -4)

    def test_search_matches_sku_and_name(self) -> None:
        inventory = Inventory()
        inventory.add_item("A100", "Keyboard", 3, Decimal("49.99"))
        inventory.add_item("M200", "Mouse", 10, Decimal("19.99"))

        self.assertEqual([item.sku for item in inventory.search("key")], ["A100"])
        self.assertEqual([item.sku for item in inventory.search("m200")], ["M200"])

    def test_format_items_handles_empty_inventory(self) -> None:
        self.assertEqual(format_items([]), "No items found.")

    def test_cli_add_and_summary(self) -> None:
        with tempfile.TemporaryDirectory() as tmpdir:
            storage = Path(tmpdir) / "inventory.json"
            add_result = main([
                "--file",
                str(storage),
                "add",
                "A100",
                "Keyboard",
                "2",
                "50",
            ])
            summary_result = main(["--file", str(storage), "summary"])

        self.assertEqual(add_result, 0)
        self.assertEqual(summary_result, 0)


if __name__ == "__main__":
    unittest.main()
