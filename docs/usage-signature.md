---
title: Signatures
order: 45
---

# Signatures

With `omnisense/omnisign` and a provider (`omnisign/docuseal`, self-hosted, or `omnisign/yousign`),
a lease and its inventory of fixtures are signed electronically: `Base\Estate\Signature\LeaseSignatures`,
over omnisense/omnibase's `Base\Service\Signatures` - one envelope about the lease
(`Base\Entity\Signature\Envelope`, table `signature_envelope`), which keeps the provider's
reference, the state, each signer's, and once completed the signed PDF and its evidence in the
private storage (`base.signatures.storage`). Without omnisign the folder `src/Signature/` is not
loaded, no table exists, and the lease's page says how to turn it on.

It is the electronic signature of a private deed (simple by default, advanced with Yousign or
DocuSign): not the notary's authentic act.

## Configuration

```yaml
# config/packages/omnisign.yaml
omnisign:
    gateways:
        docuseal:
            factory: docuseal
            options: { url: '%env(default::DOCUSEAL_URL)%', api_key: '%env(default::DOCUSEAL_API_KEY)%', webhook_secret: '%env(default::DOCUSEAL_WEBHOOK_SECRET)%' }
        yousign:
            factory: yousign
            options: { api_key: '%env(default::YOUSIGN_API_KEY)%', sandbox: '%env(bool:default::YOUSIGN_SANDBOX)%', webhook_secret: '%env(default::YOUSIGN_WEBHOOK_SECRET)%' }

# config/packages/estate.yaml
estate:
    signature:
        gateway: docuseal      # development: nothing leaves for a third party
when@prod:
    estate:
        signature:
            gateway: yousign
```

`Omnisign\Bridge\Symfony\OmnisignBundle` is registered by hand in `config/bundles.php` (omnisign has
no Flex recipe). The provider's webhook is the core's: `POST /signatures/{gateway}/webhook`
(`base_signature_webhook`), declared on the provider's side with its secret.

## What is signed

| Kind | The document | Signers, in order |
|---|---|---|
| `lease` | the lease the team uploaded (a `Document` of kind `lease` of the lease), else its particular conditions generated from the record (`@Estate/pdf/lease.html.twig`, with dompdf) | the landlord (the person who sends it, for the company), the tenants and co-tenants, the guarantors |
| `inventory` | the inventory the team uploaded (a `Document` of kind `inventory`): refused until there is one | the landlord, the tenants and co-tenants |
| `surety` | a guarantor's deed, generated (below) | the guarantor |

Each goes with a signature page (`@Estate/pdf/signatures.html.twig`) naming the document by its
file name and SHA-256, one box per signer, two to a row; each signer's signature field is placed on
their box (`Omnisign\Model\Field`, points from the top left corner). The envelope is ordered
(`ordered: true`): the provider e-mails the landlord, then the tenants, then the guarantors; it
expires after 30 days. On a provider's template (`signature.template`, DocuSeal's open-source
edition, which signs its own templates only) no PDF is sent: the signers take the template's roles
`Bailleur`, `Locataire 1`, `Locataire 2`..., `Garant 1`...

## Following it

The provider's webhook asks it again (`Signatures::notify()` → `refresh()`), and each move is a
`Base\Event\SignatureEvent`. `LeaseSignatures` listens:

- `COMPLETED`: a line in the feed, the team e-mailed (`lease_signature`), and the signed PDF filed
  as the lease's document - « Bail B-1 (signé) », kind `lease` (or `inventory`), seen by its
  tenants and filed in their cloud folders like any lease document;
- `DECLINED`, `EXPIRED`: a line in the feed and the team e-mailed; it can be sent again.

The back office's page (`estate_admin_signature`, the « Signature » button of a lease's page) shows
the state - « Envoyé, 2 signature(s) sur 4 », « Signé le 02/12/2026 », « Signature refusée »,
« Expiré sans toutes les signatures » -, each signer's, and offers to send, ask the provider again,
remind, cancel, and once signed the signed PDF and the evidence. The lease's page shows the state in
a line (« Signature électronique »).

```php
$signatures->send($lease, $user);                                  // the lease, to its signers
$signatures->send($lease, $user, LeaseSignatures::INVENTORY);      // its inventory, once uploaded
$signatures->state($lease);                                        // status, label, signed / total, signers
$signatures->file($lease, LeaseSignatures::LEASE, 'evidence');     // the evidence, once completed
```

## The guarantor's deed

**A starting point, to be validated by a lawyer.** The template was written from the texts read on
Légifrance on 2026-11-11 and checked by nobody who practises law. The back office's page says so,
until the site's owner (`ROLE_SUPERADMIN`) ticks « Modèle d'acte de cautionnement validé par un
avocat » on the company's settings (`Organisation::setSuretyTemplateConfirmed()`, its date kept).

`Signature\SuretySignatures` gives each guarantor of a lease (`LeaseParty` of role `guarantor`) a
deed of their own (acte de cautionnement), generated from the record
(`@Estate/pdf/surety.html.twig`) and signed through omnisign in its own envelope, about the
guarantor (`Signatures::of($guarantor)`, key `surety:<lease>:<party id>`):

- the parties: the guarantor (name, address, e-mail), the landlord as creditor, the tenants as
  debtors; the lease it secures;
- the rent and its revision terms « tels qu'ils figurent au contrat de location », in the words the
  generated lease uses (`@Estate/pdf/_revision_terms.html.twig`);
- the commitment: its limit in principal and accessories in words and figures
  (`SuretySignatures::inWords()`, `figures()`), its end date or « aucune indication de durée »,
  joint (the benefits of discussion and division given up) or not - set by the team on the deeds'
  page (`LeaseParty::$suretyLimit`, `$suretyUntil`, `$suretyJoint`, `$address`; `Form\SuretyTermsType`);
- for a home's lease, the reproduction word for word of art. 22-1's last paragraph but one
  (`SuretySignatures::PARAGRAPH_4`);
- the mention of art. 2297 of the code civil, which the guarantor types themselves on the signature
  page (`@Estate/pdf/surety_signatures.html.twig`): one text field (`FieldType::TEXT`) a sentence -
  the engagement and its limit; for a joint guarantee, the benefits given up -, then their signature;
- a copy of the lease in the same envelope, to read (`signable: false`).

The guarantor alone signs. The deed cannot be sent without its limit, the guarantor's e-mail, and -
for a revisable rent - the lease's reference index (`SuretySignatures::missing()`). Completed, the
signed deed is filed as the lease's document « Acte de cautionnement de … (signé) », kind
`guarantee`, **not** shown to the tenants; the team is e-mailed (`surety_signature`). The lease's
page shows each guarantor's deed and its state (« Actes de cautionnement »), and a « Cautionnement »
button opens the deeds' page (`estate_admin_surety`): the notice, the conditions of art. 22-1, al. 1
to 3 to check (never decided: estate does not know whether the landlord insured the rent, nor the
tenant's situation), each guarantor's commitment, « Lire l'acte » (the PDF as it would go), send,
refresh, remind, cancel, the signed deed and its evidence.

For a commercial or professional lease art. 22-1 does not apply: the deed carries art. 2297's
mention, no reproduction. With `signature.surety_template`, a provider's template (role `Garant`)
is signed instead - it must then carry the deed's statements itself. The rules and their sources:
[Legal rules](legal.md#the-guarantors-deed).

```php
$sureties->deeds($lease);                 // each guarantor, its state, what is missing
$sureties->send($guarantor, $user);       // the deed, the lease to read, the page with the mention
$sureties->statement($guarantor);         // the mention the guarantor types, one sentence a field
$sureties->deedPdf($guarantor);           // the deed as it goes
```

## Not yet

- A guarantee given by a company or a body (Visale): the deed is a natural person's.
- The guarantor's identity checked by the provider (an advanced signature: `signature.level`).
- Signing in the site's page (embedded): each signer signs from the provider's e-mail.
- DocuSeal's fields placed by points: checked against omnisign's DocuSeal gateway as written, not
  yet on a DocuSeal Pro instance (its open-source edition signs templates only).
