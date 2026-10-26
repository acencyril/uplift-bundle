<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

/**
 * The adoptions' titles and explanations, in the "uplift" translation domain
 * (translations/uplift.<locale>.php). The site's translator is used when it has the key,
 * so a site can override them or add its own adoptions' texts; otherwise the bundle's
 * files are read directly, so reports read the same without a translator.
 */
final class Texts
{
    public const DOMAIN = 'uplift';
    public const LOCALES = ['en', 'fr'];

    /** @var array<string, array<string, string>> */
    private array $files = [];

    /** @param object|null $translator a Symfony\Contracts\Translation\TranslatorInterface */
    public function __construct(private readonly ?object $translator = null)
    {
    }

    public function get(string $key, string $locale = 'en'): string
    {
        if (null !== $this->translator && method_exists($this->translator, 'trans')) {
            $text = $this->translator->trans($key, [], self::DOMAIN, $locale);
            if (\is_string($text) && '' !== $text && $text !== $key) {
                return $text;
            }
        }

        return $this->file($locale)[$key] ?? $this->file('en')[$key] ?? $key;
    }

    public function title(Adoption $a, string $locale = 'en'): string
    {
        return $this->get('adoption.'.$a->id().'.title', $locale);
    }

    public function summary(Adoption $a, string $locale = 'en'): string
    {
        return $this->get('adoption.'.$a->id().'.summary', $locale);
    }

    /** @return array<string, string> */
    private function file(string $locale): array
    {
        if (!isset($this->files[$locale])) {
            $path = \dirname(__DIR__, 2).'/translations/'.self::DOMAIN.'.'.$locale.'.php';
            $data = \in_array($locale, self::LOCALES, true) && is_file($path) ? require $path : [];
            $this->files[$locale] = \is_array($data) ? $data : [];
        }

        return $this->files[$locale];
    }
}
