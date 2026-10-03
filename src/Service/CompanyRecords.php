<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Organisation;
use Doctrine\ORM\EntityManagerInterface;
use Omnistate\Exception\UnavailableException;
use Omnistate\Identifier\Siren;
use Omnistate\Omnistate;

/**
 * A company's identity as the State's registers hold it, read from its SIREN
 * through glitchr/omnistate: its legal name and form, its head office and its
 * creation date from Sirene / RNE (omnistate/annuaire-entreprises), its VAT
 * number from VIES (omnistate/vies) - none when VIES does not know it, as for
 * an SCI letting homes, which is not liable. Loaded only with glitchr/omnistate.
 */
final class CompanyRecords
{
    public function __construct(
        private readonly Omnistate $omnistate,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Fills the organisation from the registers and saves it. False when it has no
     * valid SIREN or the register does not know it; a register that does not
     * answer throws (UnavailableException), and nothing is changed.
     *
     * @throws UnavailableException
     */
    public function refresh(Organisation $organisation): bool
    {
        $siren = $organisation->getSiren();
        if (null === $siren || !Siren::isValid($siren)) {
            return false;
        }
        $company = $this->omnistate->company($siren);
        if (null === $company) {
            return false;
        }

        $organisation->setLegalName($company->legalName ?? $company->name);
        if ($company->legalForm?->label) {
            $organisation->setLegalForm($company->legalForm->label);
        }
        if ($company->createdOn) {
            $organisation->setFoundedOn($company->createdOn);
        }
        if ($address = $company->headOffice?->address) {
            $organisation
                ->setAddress($address->street ? self::street($address->street) : null)
                ->setPostcode($address->postalCode)
                ->setCity($address->city ? self::words($address->city) : null);
        }

        $vat = $this->omnistate->vat(Siren::toVatNumber($siren));
        $organisation->setVatNumber($vat->valid ? $vat->number : null);

        $organisation->setRegistryCheckedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    /** "36 A RUE DE LA GANZAU" → "36 A rue de la Ganzau": registers write in capitals. */
    private static function street(string $street): string
    {
        $words = explode(' ', self::words($street));
        foreach ($words as $i => $word) {
            if (1 === $i && 1 === mb_strlen($word) && preg_match('/^\d/', $words[0])) {
                $words[$i] = mb_strtoupper($word); // the repetition index: 36 A, 12 B
            } elseif (\in_array(mb_strtolower($word), ['rue', 'avenue', 'boulevard', 'place', 'quai', 'impasse', 'allée', 'chemin', 'route', 'cours', 'passage', 'square'], true)) {
                $words[$i] = mb_strtolower($word);
            }
        }

        return implode(' ', $words);
    }

    /** "STRASBOURG" → "Strasbourg", "SAINT-LOUIS" → "Saint-Louis", "DE LA" kept small. */
    private static function words(string $text): string
    {
        $text = mb_convert_case(mb_strtolower($text), \MB_CASE_TITLE);

        return preg_replace_callback("/(?<=\\s)(De|Du|Des|La|Le|Les|Et|Sur|Sous|D'|L')(?=\\s|[A-ZÀ-Ý])/u", fn ($m) => mb_strtolower($m[1]), $text);
    }
}
