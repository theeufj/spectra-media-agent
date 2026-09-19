import unittest
from unittest.mock import patch
from proxy import public_addresses


class DestinationTests(unittest.TestCase):
    def test_private_redirect_and_subresource_destinations_are_rejected(self):
        for address in ['127.0.0.1', '169.254.169.254', '10.0.0.1', '::1', 'fe80::1', '::ffff:127.0.0.1', '100.64.0.1']:
            with self.subTest(address=address), patch('socket.getaddrinfo', return_value=[(2, 1, 6, '', (address, 443))]):
                with self.assertRaises(ValueError):
                    public_addresses('untrusted.example', 443)

    def test_mixed_public_private_dns_answers_are_rejected(self):
        with patch('socket.getaddrinfo', return_value=[(2, 1, 6, '', ('8.8.8.8', 443)), (2, 1, 6, '', ('10.0.0.1', 443))]):
            with self.assertRaises(ValueError):
                public_addresses('untrusted.example', 443)

    def test_non_web_ports_are_rejected_before_resolving(self):
        with patch('socket.getaddrinfo') as resolver:
            with self.assertRaises(ValueError):
                public_addresses('untrusted.example', 5432)
            resolver.assert_not_called()


if __name__ == '__main__':
    unittest.main()
