<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\EventListener;

use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\CoreBundle\Form\Type\SlotTextType;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailBuilderEvent;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\PageBundle\Entity\Trackable;
use Mautic\PageBundle\Model\RedirectModel;
use Mautic\PageBundle\Model\TrackableModel;

/**
 * Class BuilderSubscriber.
 */
class BuilderSubscriber extends CommonSubscriber
{
    /**
     * @var CoreParametersHelper
     */
    protected $coreParametersHelper;

    /**
     * @var EmailModel
     */
    protected $emailModel;

    /**
     * @var TrackableModel
     */
    protected $pageTrackableModel;

    /**
     * @var RedirectModel
     */
    protected $pageRedirectModel;

    /**
     * BuilderSubscriber constructor.
     *
     * @param CoreParametersHelper $coreParametersHelper
     * @param EmailModel           $emailModel
     * @param TrackableModel       $trackableModel
     * @param RedirectModel        $redirectModel
     */
    public function __construct(
        CoreParametersHelper $coreParametersHelper,
        EmailModel $emailModel,
        TrackableModel $trackableModel,
        RedirectModel $redirectModel
    ) {
        $this->coreParametersHelper = $coreParametersHelper;
        $this->emailModel           = $emailModel;
        $this->pageTrackableModel   = $trackableModel;
        $this->pageRedirectModel    = $redirectModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            EmailEvents::EMAIL_ON_BUILD => ['onEmailBuild', 0],
            EmailEvents::EMAIL_ON_SEND  => [
                ['onEmailGenerate', 0],
                // Ensure this is done last in order to catch all tokenized URLs
                ['convertUrlsToTokens', -9999],
            ],
            EmailEvents::EMAIL_ON_DISPLAY => [
                ['onEmailGenerate', 0],
                // Ensure this is done last in order to catch all tokenized URLs
                ['convertUrlsToTokens', -9999],
            ],
        ];
    }

    /**
     * @param EmailBuilderEvent $event
     */
    public function onEmailBuild(EmailBuilderEvent $event)
    {
        if ($event->tokenSectionsRequested()) {
            //add email tokens
            $content = $this->templating->render('MauticEmailBundle:SubscribedEvents\EmailToken:token.html.php');
            $event->addTokenSection('email.emailtokens', 'mautic.email.builder.index', $content);
        }

        if ($event->abTestWinnerCriteriaRequested()) {
            //add AB Test Winner Criteria
            $openRate = [
                'group'    => 'mautic.email.stats',
                'label'    => 'mautic.email.abtest.criteria.open',
                'callback' => '\Mautic\EmailBundle\Helper\AbTestHelper::determineOpenRateWinner',
            ];
            $event->addAbTestWinnerCriteria('email.openrate', $openRate);

            $clickThrough = [
                'group'    => 'mautic.email.stats',
                'label'    => 'mautic.email.abtest.criteria.clickthrough',
                'callback' => '\Mautic\EmailBundle\Helper\AbTestHelper::determineClickthroughRateWinner',
            ];
            $event->addAbTestWinnerCriteria('email.clickthrough', $clickThrough);
        }

        $tokens = [
            '{unsubscribe_text}' => $this->translator->trans('mautic.email.token.unsubscribe_text'),
            '{webview_text}'     => $this->translator->trans('mautic.email.token.webview_text'),
            '{signature}'        => $this->translator->trans('mautic.email.token.signature'),
            '{subject}'          => $this->translator->trans('mautic.email.subject'),
        ];

        if ($event->tokensRequested(array_keys($tokens))) {
            $event->addTokens(
                $event->filterTokens($tokens),
                true
            );
        }

        // these should not allow visual tokens
        $tokens = [
            '{unsubscribe_url}' => $this->translator->trans('mautic.email.token.unsubscribe_url'),
            '{webview_url}'     => $this->translator->trans('mautic.email.token.webview_url'),
        ];
        if ($event->tokensRequested(array_keys($tokens))) {
            $event->addTokens(
                $event->filterTokens($tokens)
            );
        }

        if ($event->slotTypesRequested()) {
            $event->addSlotType(
                'text',
                'Text',
                'font',
                'MauticCoreBundle:Slots:text.html.php',
                SlotTextType::class,
                1000
            );
            $event->addSlotType(
                'image',
                'Image',
                'image',
                'MauticCoreBundle:Slots:image.html.php',
                'slot',
                900
            );
            $event->addSlotType(
                'button',
                'Button',
                'external-link',
                'MauticCoreBundle:Slots:button.html.php',
                'slot_button',
                800
            );
            $event->addSlotType(
                'separator',
                'Separator',
                'minus',
                'MauticCoreBundle:Slots:separator.html.php',
                'slot',
                700
            );
        }
    }

    /**
     * @param EmailSendEvent $event
     */
    public function onEmailGenerate(EmailSendEvent $event)
    {
        $idHash = $event->getIdHash();
        $lead   = $event->getLead();
        $email  = $event->getEmail();

        if ($idHash == null) {
            // Generate a bogus idHash to prevent errors for routes that may include it
            $idHash = uniqid();
        }

        $unsubscribeText = $this->coreParametersHelper->getParameter('unsubscribe_text');
        if (!$unsubscribeText) {
            $unsubscribeText = $this->translator->trans('mautic.email.unsubscribe.text', ['%link%' => '|URL|']);
        }
        $unsubscribeText = str_replace('|URL|', $this->emailModel->buildUrl('mautic_email_unsubscribe', ['idHash' => $idHash]), $unsubscribeText);
        $event->addToken('{unsubscribe_text}', $unsubscribeText);

        $event->addToken('{unsubscribe_url}', $this->emailModel->buildUrl('mautic_email_unsubscribe', ['idHash' => $idHash]));

        $webviewText = $this->coreParametersHelper->getParameter('webview_text');
        if (!$webviewText) {
            $webviewText = $this->translator->trans('mautic.email.webview.text', ['%link%' => '|URL|']);
        }
        $webviewText = str_replace('|URL|', $this->emailModel->buildUrl('mautic_email_webview', ['idHash' => $idHash]), $webviewText);
        $event->addToken('{webview_text}', $webviewText);

        // Show public email preview if the lead is not known to prevent 404
        if (empty($lead['id']) && $email) {
            $event->addToken('{webview_url}', $this->emailModel->buildUrl('mautic_email_preview', ['objectId' => $email->getId()]));
        } else {
            $event->addToken('{webview_url}', $this->emailModel->buildUrl('mautic_email_webview', ['idHash' => $idHash]));
        }

        $signatureText = $this->coreParametersHelper->getParameter('default_signature_text');
        $fromName      = $this->coreParametersHelper->getParameter('mailer_from_name');

        if (!empty($lead['owner_id'])) {
            $owner = $this->factory->getModel('lead')->getRepository()->getLeadOwner($lead['owner_id']);
            if ($owner && !empty($owner['signature'])) {
                $fromName      = $owner['first_name'].' '.$owner['last_name'];
                $signatureText = $owner['signature'];
            }
        }

        $signatureText = str_replace('|FROM_NAME|', $fromName, nl2br($signatureText));
        $event->addToken('{signature}', $signatureText);

        $event->addToken('{subject}', $event->getSubject());
    }

    /**
     * @param EmailSendEvent $event
     *
     * @return array
     */
    public function convertUrlsToTokens(EmailSendEvent $event)
    {
        if ($event->isInternalSend()) {
            // Don't convert for previews, example emails, etc

            return;
        }

        $email   = $event->getEmail();
        $emailId = ($email) ? $email->getId() : null;

        $clickthrough = $event->generateClickthrough();
        $trackables   = $this->parseContentForUrls($event, $emailId);

        /**
         * @var string
         * @var Trackable $trackable
         */
        foreach ($trackables as $token => $trackable) {
            $url = ($trackable instanceof Trackable)
                ?
                $this->pageTrackableModel->generateTrackableUrl($trackable, $clickthrough)
                :
                $this->pageRedirectModel->generateRedirectUrl($trackable, $clickthrough);

            $event->addToken($token, $url);
        }
    }

    /**
     * Parses content for URLs and tokens.
     *
     * @param EmailSendEvent $event
     * @param                $emailId
     *
     * @return mixed
     */
    protected function parseContentForUrls(EmailSendEvent $event, $emailId)
    {
        static $convertedContent = [];

        // Prevent parsing the exact same content over and over
        if (!isset($convertedContent[$event->getContentHash()])) {
            $html = $event->getContent();
            $text = $event->getPlainText();

            $contentTokens = $event->getTokens();

            list($content, $trackables) = $this->pageTrackableModel->parseContentForTrackables(
                [$html, $text],
                $contentTokens,
                ($emailId) ? 'email' : null,
                $emailId
            );

            list($html, $text) = $content;
            unset($content);

            if ($html) {
                $event->setContent($html);
            }
            if ($text) {
                $event->setPlainText($text);
            }

            $convertedContent[$event->getContentHash()] = $trackables;

            // Don't need to preserve Trackable or Redirect entities in memory
            $this->em->clear('Mautic\PageBundle\Entity\Redirect');
            $this->em->clear('Mautic\PageBundle\Entity\Trackable');

            unset($html, $text, $trackables);
        }

        return $convertedContent[$event->getContentHash()];
    }
}
