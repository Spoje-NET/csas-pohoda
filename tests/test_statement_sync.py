"""
Unit tests for statement_sync.py
"""
import unittest
import os
from unittest.mock import patch, MagicMock
from src.statement_sync import download_statements, import_statement_to_pohoda

class TestStatementSync(unittest.TestCase):
    """
    Unit tests for statement download and import functions.
    """
    @patch('src.statement_sync.StatementDownloader')
    def test_download_statements_success(self, mock_downloader):
        mock_instance = mock_downloader.return_value
        mock_instance.download.return_value = '/tmp/statement.abo'
        result = download_statements('2025-09-01', '2025-09-15', '/tmp')
        self.assertEqual(result, '/tmp/statement.abo')

    @patch('src.statement_sync.StatementDownloader')
    def test_download_statements_failure(self, mock_downloader):
        mock_instance = mock_downloader.return_value
        mock_instance.download.side_effect = Exception('Download error')
        result = download_statements('2025-09-01', '2025-09-15', '/tmp')
        self.assertIsNone(result)

    @patch('src.statement_sync.PohodaImporter')
    def test_import_statement_to_pohoda_success(self, mock_importer):
        mock_instance = mock_importer.return_value
        mock_instance.import_statement.return_value = True
        result = import_statement_to_pohoda('/tmp/statement.abo', 'http://localhost', 'token')
        self.assertTrue(result)

    @patch('src.statement_sync.PohodaImporter')
    def test_import_statement_to_pohoda_failure(self, mock_importer):
        mock_instance = mock_importer.return_value
        mock_instance.import_statement.side_effect = Exception('Import error')
        result = import_statement_to_pohoda('/tmp/statement.abo', 'http://localhost', 'token')
        self.assertFalse(result)

if __name__ == '__main__':
    unittest.main()
