<?php

declare(strict_types=1);

/**
 * Branded Prime CMS HTML e-mail layout.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $subject
 * @var string $siteName
 */
$siteName = $siteName ?? 'Prime CMS';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($subject ?? $siteName) ?></title>
</head>
<body style="margin:0;padding:24px 16px;background-color:#F7F8FA;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;line-height:1.55;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;width:100%;background-color:#FFFFFF;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background-color:#111827;padding:20px 28px;">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:3px;background-color:#F97316;margin-right:8px;vertical-align:middle;"></span>
                            <span style="color:#FFFFFF;font-weight:700;font-size:16px;letter-spacing:-0.01em;vertical-align:middle;"><?= $this->e($siteName) ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;color:#111827;font-size:15px;">
                            <?= $this->raw($this->section('content')) ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background-color:#F9FAFB;border-top:1px solid #E5E7EB;color:#6B7280;font-size:12px;">
                            <?= $this->e($this->t('mail.layout.pied_de_page', ['site' => $siteName])) ?>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
