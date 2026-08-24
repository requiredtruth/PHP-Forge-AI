from pathlib import Path
import re
import unittest


SOURCE = (Path(__file__).parents[1] / "php_forge_ai.php").read_text(encoding="utf-8")


class StaticContractTests(unittest.TestCase):
    def test_single_file_has_no_composer_or_database_dependency(self):
        self.assertNotIn("vendor/autoload", SOURCE)
        self.assertNotRegex(SOURCE, r"\b(?:PDO|mysqli|SQLite3)\b")

    def test_security_and_parser_controls_exist(self):
        for primitive in ("hash_equals", "TOKEN_PARSE", "random_bytes", "CURLOPT_SSL_VERIFYPEER"):
            self.assertIn(primitive, SOURCE)
        self.assertIn("PFA_LIBRARY_ONLY", SOURCE)

    def test_private_attribution_and_secrets_are_absent(self):
        blocked = ("@author", "private key", "seed phrase", "api_key", "password=")
        lowered = SOURCE.lower()
        for phrase in blocked:
            self.assertNotIn(phrase, lowered)

    def test_expected_ui_controls_exist(self):
        ids = ("cleanStart", "downloadOnly", "initOnly", "reset", "startTrain", "stopTrain", "forge", "workStatus")
        for element_id in ids:
            self.assertRegex(SOURCE, rf'id="{element_id}"')


if __name__ == "__main__":
    unittest.main()

