<?php

declare(strict_types=1);

namespace HostingerSpace\Detector;

/**
 * Extraction de valeurs dans les fichiers de configuration.
 *
 * Les fichiers PHP sont lus avec le tokenizer du langage, pas avec des
 * expressions regulieres. C'est ce qui permet de ne pas se faire piéger par :
 *
 *   // define('DB_NAME', 'ancienne_base');   <- ligne commentee, ignoree
 *   define('DB_PASSWORD', 'mot\'de"passe');  <- guillemets echappes
 *
 * Une regex prendrait la premiere pour argent comptant et tronquerait la
 * seconde ; on rattacherait alors le site a la mauvaise base.
 */
final class Parse
{
    /**
     * Toutes les constantes definies par define('NOM', 'valeur').
     *
     * @return array<string,string>
     */
    public static function phpDefines(string $source): array
    {
        $tokens = self::tokens($source);
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_STRING || strcasecmp($tokens[$i]['text'], 'define') !== 0) {
                continue;
            }

            if (($tokens[$i + 1]['text'] ?? '') !== '('
                || ($tokens[$i + 2]['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING
                || ($tokens[$i + 3]['text'] ?? '') !== ','
                || ($tokens[$i + 4]['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $name = self::unquote($tokens[$i + 2]['text']);

            // Comme PHP, la premiere definition l'emporte.
            $found[$name] ??= self::unquote($tokens[$i + 4]['text']);
        }

        return $found;
    }

    public static function phpDefine(string $source, string $name): ?string
    {
        return self::phpDefines($source)[$name] ?? null;
    }

    /**
     * Affectations scalaires de variables et de proprietes :
     * `$table_prefix = 'wp_';` ou `public $db = 'base';`
     *
     * @return array<string,string>
     */
    public static function phpVariables(string $source): array
    {
        $tokens = self::tokens($source);
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_VARIABLE
                || ($tokens[$i + 1]['text'] ?? '') !== '='
                || ($tokens[$i + 2]['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $name = ltrim($tokens[$i]['text'], '$');
            $found[$name] ??= self::unquote($tokens[$i + 2]['text']);
        }

        return $found;
    }

    public static function phpVariable(string $source, string $name): ?string
    {
        return self::phpVariables($source)[$name] ?? null;
    }

    /**
     * Entrees de tableau ecrites `'cle' => 'valeur'`.
     *
     * Les cles imbriquees ne sont pas distinguees : on renvoie la premiere
     * valeur rencontree pour chaque cle, ce qui suffit aux fichiers de
     * configuration ou 'database' ou 'dbname' n'apparaissent qu'une fois.
     *
     * @return array<string,string>
     */
    public static function phpArrayEntries(string $source): array
    {
        $tokens = self::tokens($source);
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING
                || ($tokens[$i + 1]['id'] ?? null) !== T_DOUBLE_ARROW
                || ($tokens[$i + 2]['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $key = self::unquote($tokens[$i]['text']);
            $found[$key] ??= self::unquote($tokens[$i + 2]['text']);
        }

        return $found;
    }

    public static function phpArrayValue(string $source, string $key): ?string
    {
        return self::phpArrayEntries($source)[$key] ?? null;
    }

    /**
     * Valeur d'une constante de classe : `const VERSION = '10.2.1';`
     * ou `public const MAJOR_VERSION = 5;`
     */
    public static function phpClassConstant(string $source, string $name): ?string
    {
        $tokens = self::tokens($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_CONST
                || ($tokens[$i + 1]['id'] ?? null) !== T_STRING
                || $tokens[$i + 1]['text'] !== $name
                || ($tokens[$i + 2]['text'] ?? '') !== '=') {
                continue;
            }

            $value = $tokens[$i + 3] ?? null;

            if ($value === null) {
                return null;
            }

            return match ($value['id']) {
                T_CONSTANT_ENCAPSED_STRING => self::unquote($value['text']),
                T_LNUMBER, T_DNUMBER => $value['text'],
                default => null,
            };
        }

        return null;
    }

    /**
     * Arguments litteraux des appels a une fonction donnee.
     *
     * Sert aux vieux sites PHP ecrits a la main, ou la connexion est un simple
     * mysqli_connect('localhost', 'u1_user', 'secret', 'u1_base') sans aucune
     * constante nommee. Un argument non litteral (variable, concatenation,
     * appel) est rendu null : on sait qu'il y a un argument, mais pas sa valeur.
     *
     * @return array<int,array<int,?string>> Un tableau d'arguments par appel.
     */
    public static function phpCallArguments(string $source, string $function): array
    {
        $tokens = self::tokens($source);
        $count = count($tokens);
        $calls = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_STRING
                || strcasecmp($tokens[$i]['text'], $function) !== 0
                || ($tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }

            $depth = 0;
            $arguments = [];
            $current = [];

            for ($j = $i + 1; $j < $count; $j++) {
                $text = $tokens[$j]['text'];

                if ($text === '(' || $text === '[') {
                    $depth++;

                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($text === ')' || $text === ']') {
                    $depth--;

                    if ($depth === 0) {
                        $arguments[] = $current;
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $arguments[] = $current;
                    $current = [];
                    continue;
                }

                $current[] = $tokens[$j];
            }

            $calls[] = array_map(
                static fn (array $argument): ?string => count($argument) === 1
                    && $argument[0]['id'] === T_CONSTANT_ENCAPSED_STRING
                        ? self::unquote($argument[0]['text'])
                        : null,
                $arguments
            );

            $i = $j ?? $i;
        }

        return $calls;
    }

    /**
     * Fichier .env : KEY=VALUE, avec guillemets, commentaires et « export ».
     *
     * @return array<string,string>
     */
    public static function dotenv(string $source): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $equals = strpos($line, '=');

            if ($equals === false) {
                continue;
            }

            $key = rtrim(substr($line, 0, $equals));
            $value = ltrim(substr($line, $equals + 1));

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key)) {
                continue;
            }

            $values[$key] = self::dotenvValue($value);
        }

        return $values;
    }

    private static function dotenvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];

        if ($quote === '"' || $quote === "'") {
            // Chaine citee : on va jusqu'au guillemet fermant non echappe, ce
            // qui laisse tranquilles les « # » et espaces d'un mot de passe.
            $pattern = $quote === '"' ? '/^"((?:[^"\\\\]|\\\\.)*)"/' : "/^'((?:[^'\\\\]|\\\\.)*)'/";

            if (preg_match($pattern, $value, $matches) === 1) {
                return $quote === '"'
                    ? str_replace(['\\"', '\\n', '\\r', '\\t', '\\\\'], ['"', "\n", "\r", "\t", '\\'], $matches[1])
                    : str_replace(["\\'", '\\\\'], ["'", '\\'], $matches[1]);
            }
        }

        // Non citee : un « # » demarre un commentaire de fin de ligne.
        $hash = strpos($value, ' #');

        if ($hash !== false) {
            $value = substr($value, 0, $hash);
        }

        return trim($value);
    }

    /**
     * URL de connexion Symfony / Doctrine :
     * mysql://utilisateur:motdepasse@hote:3306/base?serverVersion=8.0
     *
     * @return array{driver:string,user:?string,password:?string,host:string,port:int,database:string}|null
     */
    public static function databaseUrl(string $url): ?array
    {
        $url = trim($url);

        if ($url === '' || !str_contains($url, '://')) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'])) {
            return null;
        }

        $driver = strtolower($parts['scheme']);

        if (!in_array($driver, ['mysql', 'mysqli', 'mysql2', 'mariadb', 'pdo-mysql', 'pdo_mysql'], true)) {
            return null;
        }

        return [
            'driver' => $driver,
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            'host' => isset($parts['host']) ? rawurldecode($parts['host']) : 'localhost',
            'port' => (int) ($parts['port'] ?? 3306) ?: 3306,
            'database' => ltrim(rawurldecode($parts['path'] ?? ''), '/'),
        ];
    }

    /**
     * Chaine DSN PDO : « mysql:host=localhost;dbname=base;charset=utf8mb4 »
     *
     * @return array<string,string>
     */
    public static function pdoDsn(string $dsn): array
    {
        $colon = strpos($dsn, ':');

        if ($colon === false) {
            return [];
        }

        $values = [];

        foreach (explode(';', substr($dsn, $colon + 1)) as $pair) {
            $equals = strpos($pair, '=');

            if ($equals !== false) {
                $values[trim(substr($pair, 0, $equals))] = trim(substr($pair, $equals + 1));
            }
        }

        return $values;
    }

    /** Version d'un paquet dans un composer.lock. */
    public static function composerLockVersion(string $json, string $package): ?string
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($data[$section] ?? [] as $entry) {
                if (is_array($entry) && ($entry['name'] ?? null) === $package) {
                    return self::cleanVersion((string) ($entry['version'] ?? ''));
                }
            }
        }

        return null;
    }

    /**
     * Separe hote et port. DB_HOST accepte « hote:port », mais aussi
     * « localhost:/var/run/mysqld/mysqld.sock » : un chemin de socket derriere
     * les deux-points n'est pas un port, et le prendre pour tel donnerait un
     * port 0 et une connexion impossible.
     *
     * @return array{0:string,1:int}
     */
    public static function hostAndPort(string $host, int $defaultPort = 3306): array
    {
        $host = trim($host);

        if ($host === '') {
            return ['localhost', $defaultPort];
        }

        $colon = strrpos($host, ':');

        if ($colon === false) {
            return [$host, $defaultPort];
        }

        $suffix = substr($host, $colon + 1);

        if (preg_match('/^\d+$/', $suffix) !== 1) {
            return [substr($host, 0, $colon), $defaultPort];
        }

        return [substr($host, 0, $colon), (int) $suffix];
    }

    public static function cleanVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $version = ltrim(trim($version), 'vV');

        return $version === '' ? null : $version;
    }

    /**
     * Decoupe une source PHP en jetons, sans espaces ni commentaires.
     *
     * @return array<int,array{id:?int,text:string}>
     */
    private static function tokens(string $source): array
    {
        if (!str_contains($source, '<?php') && !str_contains($source, '<?')) {
            $source = "<?php\n" . $source;
        }

        $raw = @token_get_all($source);
        $tokens = [];

        foreach ($raw as $token) {
            if (is_string($token)) {
                $tokens[] = ['id' => null, 'text' => $token];
                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = ['id' => $token[0], 'text' => $token[1]];
        }

        return $tokens;
    }

    /** Retire les guillemets d'un litteral PHP et defait ses echappements. */
    public static function unquote(string $literal): string
    {
        $literal = trim($literal);

        if (strlen($literal) < 2) {
            return $literal;
        }

        $quote = $literal[0];

        if ($quote !== '"' && $quote !== "'" || $literal[-1] !== $quote) {
            return $literal;
        }

        $inner = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
            : str_replace(['\\"', '\\n', '\\r', '\\t', '\\$', '\\\\'], ['"', "\n", "\r", "\t", '$', '\\'], $inner);
    }
}
