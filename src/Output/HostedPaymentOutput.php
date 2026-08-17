<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Output;

/**
 * Output of UnifiedApiHostedPaymentService::createHostedPayment() — unlike OperationData, its
 * constructor holds no validation, since it's produced entirely internally from a Unified API
 * response the service has already checked for a 2xx status, and never crosses an external
 * boundary itself (same reasoning as AuthorizationRequestOutput).
 *
 * redirectUrl/redirectHtml distinguish a direct success from the two documented 3DS-pending
 * shapes (see https://payplug.gitbook.io/payplug/.../3d-secure-implementation/using-payplugs-3ds-module):
 * both null means the payment was processed synchronously. redirectHtml is the "recommended for
 * web" shape — the Unified API's response carries a Base64-encoded `redirect.html` block, already
 * decoded here into the raw HTML string the CMS plugin must inject into its own page (it contains
 * a form that auto-submits the end user to the bank's challenge page). redirectUrl is the "raw"
 * mode shape (`card.threeDSecure.displayMode=raw` on the request) — a bare `redirect.url`; per the
 * same doc this still isn't a plain redirect target on its own (the response also carries
 * `redirect.postParams` a caller must POST alongside it), so redirectUrl alone only covers a caller
 * not using raw mode who still wants the URL for its own purposes. Mapping the eventual outcome to
 * a PaymentOutcome constant is a separate concern (see PRE-3588), handled once the asynchronous
 * webhook/3DS-return confirmation comes back — this class only carries what's known synchronously,
 * at creation time.
 */
final class HostedPaymentOutput
{
    /** @var int */
    public $status;

    /** @var string */
    public $body;

    /** @var string|null */
    public $redirectUrl;

    /** @var string|null */
    public $redirectHtml;

    public function __construct(int $status, string $body, ?string $redirectUrl, ?string $redirectHtml = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->redirectUrl = $redirectUrl;
        $this->redirectHtml = $redirectHtml;
    }
}
