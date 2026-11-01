<?php declare(strict_types=1);

final class GraphProductConfig
{
    public static function isConfigured(?array $config = null): bool
    {
        try {
            $config ??= ecw_graph_config();
            foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
                if (!is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                    return false;
                }
            }
            $url = parse_url($config['redirect_uri']);
            return filter_var($config['redirect_uri'], FILTER_VALIDATE_URL) !== false
                && (($url['scheme'] ?? '') === 'https' || (getenv('ECW_LOCAL_FEATURES') === '1' && ($url['scheme'] ?? '') === 'http' && ($url['host'] ?? '') === 'localhost')) && !isset($url['user']) && !isset($url['pass'])
                && !isset($url['fragment']) && !isset($url['query']);
        } catch (Throwable $e) {
            return false;
        }
    }
}
