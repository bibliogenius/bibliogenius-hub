//! Input validation utilities.

/// Validate that a string looks like a UUID (hex + dashes, 36 chars).
pub fn is_valid_uuid(s: &str) -> bool {
    s.len() == 36
        && s.bytes().all(|b| b.is_ascii_hexdigit() || b == b'-')
        && s.chars().filter(|&c| c == '-').count() == 4
}

/// Validate token format: 64-char hex string (256 bits).
pub fn is_valid_token(s: &str) -> bool {
    s.len() == 64 && s.bytes().all(|b| b.is_ascii_hexdigit())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn valid_uuid() {
        assert!(is_valid_uuid("550e8400-e29b-41d4-a716-446655440000"));
    }

    #[test]
    fn invalid_uuid_too_short() {
        assert!(!is_valid_uuid("550e8400-e29b-41d4"));
    }

    #[test]
    fn invalid_uuid_bad_chars() {
        assert!(!is_valid_uuid("550e8400-e29b-41d4-a716-44665544000g"));
    }

    #[test]
    fn invalid_uuid_no_dashes() {
        assert!(!is_valid_uuid("550e8400e29b41d4a716446655440000xxxx"));
    }

    #[test]
    fn valid_token() {
        assert!(is_valid_token(
            "aabbccdd00112233aabbccdd00112233aabbccdd00112233aabbccdd00112233"
        ));
    }

    #[test]
    fn invalid_token_too_short() {
        assert!(!is_valid_token("aabbccdd"));
    }

    #[test]
    fn invalid_token_bad_chars() {
        assert!(!is_valid_token(
            "aabbccdd00112233aabbccdd00112233aabbccdd00112233aabbccdd0011223g"
        ));
    }
}
