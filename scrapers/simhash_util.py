"""
SimHash utility for content fingerprinting and duplicate detection
"""

import hashlib


class SimHash:
    """Simple SimHash implementation for near-duplicate detection"""

    def __init__(self, text, hashbits=64):
        self.hashbits = hashbits
        self.hash = self.compute(text)

    def compute(self, text):
        """Compute SimHash fingerprint"""
        if not text:
            return 0

        # Tokenize into features (words 3+ chars)
        tokens = [w.lower() for w in text.split() if len(w) >= 3]

        if not tokens:
            return 0

        # Initialize bit vector
        v = [0] * self.hashbits

        # For each token, hash it and add to vector
        for token in tokens:
            # Hash the token to get a binary representation
            h = int(hashlib.md5(token.encode()).hexdigest(), 16)

            # For each bit position
            for i in range(self.hashbits):
                # If bit is set, increment; otherwise decrement
                if h & (1 << i):
                    v[i] += 1
                else:
                    v[i] -= 1

        # Convert vector to fingerprint
        fingerprint = 0
        for i in range(self.hashbits):
            if v[i] > 0:
                fingerprint |= (1 << i)

        return fingerprint

    def distance(self, other):
        """Calculate Hamming distance between two hashes"""
        x = (self.hash ^ other.hash) & ((1 << self.hashbits) - 1)
        return bin(x).count('1')

    def __int__(self):
        return self.hash
