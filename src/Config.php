<?php

declare(strict_types=1);

namespace HostingerSpace;

/**
 * Acces type au fichier de configuration, avec valeurs par defaut et validation.
 */
final class Config
{
    /** @param array<string,mixed> $values */
    private function __construct(private readonly array $values, public readonly string $sourcePath)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= self::defaultPath();

        if (!is_file($path)) {
            $example = dirname($path) . '/config.example.php';
            throw new \RuntimeException(
                "Configuration introuvable : {$path}\n" .
                "Cree-la a partir du modele :\n" .
                "    cp " . $example . " " . $path
            );
        }

        $values = require $path;

        if (!is_array($values)) {
            throw new \RuntimeException("Le fichier {$path} doit retourner un tableau PHP.");
        }

        return new self($values, $path);
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values, string $sourcePath = '(memoire)'): self
    {
        return new self($values, $sourcePath);
    }

    public static function defaultPath(): string
    {
        return dirname(__DIR__) . '/config/config.php';
    }

    /**
     * Lecture par chemin pointe : $config->get('ssh.host').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current ?? $default;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key, $default);

        return $value === null ? null : (string) $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /** @return array<int,string> */
    public function stringList(string $key): array
    {
        $value = $this->get($key, []);

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(strval(...), $value));
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->get('mode', 'ssh')));

        if (!in_array($mode, ['ssh', 'local'], true)) {
            throw new \RuntimeException("Mode inconnu : « {$mode} ». Valeurs acceptees : 'ssh' ou 'local'.");
        }

        return $mode;
    }

    /**
     * Chemin de la liste de bases declaree depuis hPanel.
     *
     * La cle « mysql.known_databases_file » est arrivee apres coup : une
     * configuration ecrite avant elle ne la porte pas. Sans repli, l'import
     * ecrivait bien le fichier a cote du config.php, mais le releve ne le
     * relisait jamais — la liste importee disparaissait en silence, et les
     * bases orphelines restaient introuvables sans le moindre message.
     *
     * On retombe donc sur « databases.txt », voisin du fichier de
     * configuration : le meme chemin des deux cotes, cle presente ou non.
     */
    public function knownDatabasesFile(): ?string
    {
        $configured = $this->string('mysql.known_databases_file');

        if ($configured !== null && trim($configured) !== '') {
            return $configured;
        }

        // Une configuration montee en memoire n'a pas de voisinage sur le
        // disque, et dirname() y repond « . » : sans ce garde-fou, on lirait
        // le databases.txt du dossier courant, au hasard de l'endroit d'ou la
        // commande est lancee.
        if (!str_contains($this->sourcePath, DIRECTORY_SEPARATOR)) {
            return null;
        }

        $directory = dirname($this->sourcePath);

        return is_dir($directory) ? $directory . '/databases.txt' : null;
    }

    public function storagePath(): string
    {
        $path = $this->string('storage.database') ?? dirname(__DIR__) . '/var/inventory.sqlite';
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de creer le dossier de stockage : {$dir}");
        }

        return $path;
    }

    /**
     * Verifie la coherence de la configuration avant un scan.
     *
     * @return array<int,string> Liste des problemes trouves (vide si tout va bien).
     */
    public function validate(): array
    {
        $problems = [];

        if ($this->mode() === 'ssh') {
            foreach (['ssh.host' => 'hote SSH', 'ssh.username' => 'utilisateur SSH'] as $key => $label) {
                if (in_array(trim((string) $this->get($key, '')), ['', '145.14.xxx.xxx'], true)) {
                    $problems[] = "Le {$label} n'est pas renseigne (« {$key} »).";
                }
            }

            $key = $this->string('ssh.private_key_path');
            $password = $this->string('ssh.password');

            if ($key === null && ($password === null || $password === '')) {
                $problems[] = "Aucune methode d'authentification SSH : renseigne « ssh.private_key_path » ou « ssh.password ».";
            }

            if ($key !== null && !is_file($key)) {
                $problems[] = "Cle privee introuvable : {$key}";
            }
        }

        if ($this->string('mysql.admin_user') === null && !$this->bool('mysql.use_discovered_credentials', true)) {
            $problems[] = "Aucun acces MySQL possible : renseigne « mysql.admin_user » ou active « mysql.use_discovered_credentials ».";
        }

        return $problems;
    }
}
