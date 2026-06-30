<?php
/**
 * validate.php
 * Centralized input validation / sanitization helpers.
 * Include this in any page that accepts user input.
 */

if (!function_exists('isValidUsername')) {
    /**
     * Username rule: letters and spaces ONLY.
     * No numbers, no symbols, no SQL/script-injection characters.
     * Length: 2–50 characters.
     */
    function isValidUsername(string $username): bool
    {
        return (bool) preg_match('/^[A-Za-z ]{2,50}$/', $username);
    }
}

if (!function_exists('isValidEmail')) {
    /**
     * Strict email validation.
     * FILTER_VALIDATE_EMAIL alone is too permissive (it allows things
     * like quoted strings and comments per RFC 5321/5322), so we also
     * whitelist the character set actually used in real-world emails.
     */
    function isValidEmail(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email);
    }
}

if (!function_exists('isValidPassword')) {
    /**
     * Password rule: 8–72 characters (bcrypt's hard limit is 72 bytes),
     * must contain at least one letter and one number.
     * Symbols ARE allowed here (they make passwords stronger) and are
     * never embedded in SQL directly — they only ever pass through
     * password_hash()/password_verify(), so no injection risk.
     */
    function isValidPassword(string $password): bool
    {
        if (strlen($password) < 8 || strlen($password) > 72) {
            return false;
        }
        return (bool) preg_match('/^(?=.*[A-Za-z])(?=.*\d)[\x20-\x7E]+$/', $password);
    }
}

if (!function_exists('hasDangerousChars')) {
    /**
     * Defense-in-depth: reject characters commonly used in SQL
     * injection / XSS / command injection payloads, even though
     * PDO prepared statements already neutralize SQL injection and
     * htmlspecialchars() already neutralizes stored XSS on output.
     * This blocks the attempt from ever being processed at all.
     */
    function hasDangerousChars(string $value): bool
    {
        $patterns = [
            '/[<>]/',                 // HTML/script tags
            '/[\'";`]/',              // quote/statement-terminator chars
            '/--/',                   // SQL comment
            '/\/\*/',                 // SQL block comment open
            '/\x00/',                 // null byte
            '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|EXEC)\b/i', // SQL keywords
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $value)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('cleanInput')) {
    /** Trim + strip control characters from raw POST/GET input. */
    function cleanInput(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value); // strip control chars
        return $value ?? '';
    }
}
?>
