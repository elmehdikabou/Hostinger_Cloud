<?php

declare(strict_types=1);

namespace HostingerSpace\Web;

/**
 * Controle d'acces a l'interface web.
 *
 * L'inventaire est une carte detaillee de l'hebergement : noms de bases,
 * utilisateurs MySQL, chemins sur le disque, et la liste des faiblesses
 * reperees. Publie tel quel, c'est un mode d'emploi pour attaquer le compte.
 *
 * La regle est donc fermee par defaut : hors de la machine locale, rien ne
 * s'affiche tant qu'un mot de passe n'a pas ete configure puis saisi. Une
 * interface deposee sur un sous-domaine sans mot de passe ne montre pas les
 * donnees « en attendant » — elle explique comment la proteger.
 */
final class Auth
{
    private const SESSION_KEY = 'hspace_authenticated';
    private const ATTEMPTS_KEY = 'hspace_attempts';
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 60;

    public function __construct(
        private readonly ?string $passwordHash,
        /** Vrai quand la requete vient de la machine elle-meme. */
        private readonly bool $localClient,
    ) {
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function fromRequest(?string $passwordHash, array $server): self
    {
        return new self($passwordHash, self::isLocalClient($server));
    }

    /**
     * Une requete locale vient forcement de la personne qui a lance le
     * serveur : « hspace serve » sur son poste n'a pas a demander un mot de
     * passe a son proprietaire.
     *
     * @param array<string,mixed> $server
     */
    public static function isLocalClient(array $server): bool
    {
        $address = (string) ($server['REMOTE_ADDR'] ?? '');

        return in_array($address, ['127.0.0.1', '::1'], true);
    }

    public function configured(): bool
    {
        return $this->passwordHash !== null && trim($this->passwordHash) !== '';
    }

    /** L'interface peut-elle afficher les donnees ? */
    public function allowed(): bool
    {
        if ($this->localClient) {
            return true;
        }

        return $this->configured() && $this->isLoggedIn();
    }

    /**
     * Vrai quand l'interface est exposee hors du poste local sans mot de
     * passe : on refuse alors d'afficher quoi que ce soit.
     */
    public function needsSetup(): bool
    {
        return !$this->localClient && !$this->configured();
    }

    public function isLoggedIn(): bool
    {
        $this->startSession();

        return ($_SESSION[self::SESSION_KEY] ?? null) === $this->fingerprint();
    }

    /**
     * @return bool Vrai si le mot de passe est accepte.
     */
    public function attempt(string $password): bool
    {
        $this->startSession();

        if (!$this->configured() || $this->lockedFor() > 0) {
            return false;
        }

        // password_verify compare en temps constant et bcrypt est lent par
        // construction : c'est ce qui rend l'essai en masse impraticable.
        if (!password_verify($password, (string) $this->passwordHash)) {
            $attempts = (int) ($_SESSION[self::ATTEMPTS_KEY]['count'] ?? 0) + 1;
            $_SESSION[self::ATTEMPTS_KEY] = ['count' => $attempts, 'at' => time()];

            return false;
        }

        // Nouvel identifiant de session apres authentification : un
        // identifiant connu d'avance ne donne ainsi aucun acces.
        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = $this->fingerprint();
        unset($_SESSION[self::ATTEMPTS_KEY]);

        return true;
    }

    /** Secondes restantes avant de pouvoir reessayer, 0 si aucune attente. */
    public function lockedFor(): int
    {
        $this->startSession();

        $attempts = $_SESSION[self::ATTEMPTS_KEY] ?? null;

        if (!is_array($attempts) || (int) ($attempts['count'] ?? 0) < self::MAX_ATTEMPTS) {
            return 0;
        }

        $elapsed = time() - (int) ($attempts['at'] ?? 0);

        return max(0, self::LOCKOUT_SECONDS - $elapsed);
    }

    public function logout(): void
    {
        $this->startSession();

        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    /**
     * Lie la session au mot de passe en vigueur : changer le mot de passe
     * dans la configuration invalide immediatement les sessions ouvertes.
     */
    private function fingerprint(): string
    {
        return hash('sha256', (string) $this->passwordHash);
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }

        $https = ($_SERVER['HTTPS'] ?? '') !== ''
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            // Le drapeau « secure » n'est pose que sur une connexion chiffree :
            // l'imposer en HTTP empecherait toute connexion sans rien proteger.
            'secure' => $https,
        ]);

        session_name('hspace_session');
        @session_start();
    }

    /** Jeton anti-rejeu pour le formulaire de connexion. */
    public function csrfToken(): string
    {
        $this->startSession();

        if (!isset($_SESSION['hspace_csrf']) || !is_string($_SESSION['hspace_csrf'])) {
            $_SESSION['hspace_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['hspace_csrf'];
    }

    public function csrfValid(?string $token): bool
    {
        $this->startSession();

        $expected = $_SESSION['hspace_csrf'] ?? null;

        return is_string($expected) && is_string($token) && hash_equals($expected, $token);
    }

    /** Fabrique le condense a ranger dans « web.password_hash ». */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
