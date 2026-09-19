"""Public-web-only CONNECT proxy. Resolve once, validate all answers, connect by IP."""
import http.server
import ipaddress
import select
import socket
import urllib.parse


def public_addresses(host, port):
    if port not in (80, 443):
        raise ValueError("Only web ports are allowed")
    answers = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    if not answers or any(not ipaddress.ip_address(answer[4][0]).is_global for answer in answers):
        raise ValueError("Destination is not public")
    return answers


def connect(host, port):
    answers = public_addresses(host, port)
    family, kind, proto, _, address = answers[0]
    upstream = socket.socket(family, kind, proto)
    upstream.settimeout(20)
    upstream.connect(address)  # Never resolve the hostname a second time.
    return upstream


class Proxy(http.server.BaseHTTPRequestHandler):
    timeout = 30

    def tunnel(self, upstream):
        total = 0
        while True:
            ready, _, _ = select.select([self.connection, upstream], [], [], 20)
            if not ready:
                return
            for source in ready:
                data = source.recv(65536)
                total += len(data)
                if not data or total > 30_000_000:
                    return
                (upstream if source is self.connection else self.connection).sendall(data)

    def do_CONNECT(self):
        try:
            target = urllib.parse.urlsplit('//'+self.path)
            with connect(target.hostname, target.port or 443) as upstream:
                self.send_response(200)
                self.end_headers()
                self.tunnel(upstream)
        except (ValueError, OSError):
            self.close_connection = True

    def do_GET(self):
        try:
            target = urllib.parse.urlsplit(self.path)
            if target.scheme != 'http' or target.username or target.password:
                raise ValueError("Invalid proxy URL")
            with connect(target.hostname, target.port or 80) as upstream:
                path = urllib.parse.urlunsplit(('', '', target.path or '/', target.query, ''))
                # Do not relay credentials, hop-by-hop headers or user-selected hosts.
                request = f'GET {path} HTTP/1.1\r\nHost: {target.netloc}\r\nConnection: close\r\n\r\n'
                upstream.sendall(request.encode('ascii'))
                total = 0
                while data := upstream.recv(65536):
                    total += len(data)
                    if total > 10_000_000:
                        return
                    self.connection.sendall(data)
        except (ValueError, OSError, UnicodeError):
            self.close_connection = True

    def log_message(self, *_):
        pass  # URLs can contain customer data.


if __name__ == '__main__':
    http.server.ThreadingHTTPServer(('0.0.0.0', 8080), Proxy).serve_forever()
