<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Manager\SiteManager;
use App\Service\Logger;

class MailController extends Controller
{
    private const DKIM_KEYS_DIR = '/etc/opendkim/keys';
    private const DKIM_SIGNING_TABLE = '/etc/opendkim/signing.table';
    private const DKIM_KEY_TABLE = '/etc/opendkim/key.table';
    private const DKIM_SELECTOR = 'mail';

    private SiteManager $siteEntityManager;

    public function __construct(
        TranslatorInterface $translator,
        Logger $logger,
        SiteManager $siteEntityManager
    ) {
        parent::__construct($translator, $logger);
        $this->siteEntityManager = $siteEntityManager;
    }

    public function index(Request $request, string $domainName): Response
    {
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);

        if (null === $siteEntity) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        $dkimData = $this->getDkimData($domainName);
        $serverIpv4 = $this->getServerIpv4();
        $serverIpv6 = $this->getServerIpv6();
        $serverHostname = gethostname() ?: 'localhost';

        $spfRecord = $this->buildSpfRecord($serverIpv4, $serverIpv6);
        $dmarcRecord = $this->buildDmarcRecord();

        $dnsStatus = [
            'spf' => $this->checkDnsRecord($domainName, 'TXT', 'v=spf1'),
            'dkim' => $this->checkDnsRecord(self::DKIM_SELECTOR . '._domainkey.' . $domainName, 'TXT', 'v=DKIM1'),
            'dmarc' => $this->checkDnsRecord('_dmarc.' . $domainName, 'TXT', 'v=DMARC1'),
        ];

        return $this->render('Frontend/Site/mail.html.twig', [
            'site' => $siteEntity,
            'user' => $this->getUser(),
            'formErrors' => [],
            'dkimExists' => $dkimData['exists'],
            'dkimPublicKey' => $dkimData['publicKey'],
            'dkimDnsRecord' => $dkimData['dnsRecord'],
            'spfRecord' => $spfRecord,
            'dmarcRecord' => $dmarcRecord,
            'dnsStatus' => $dnsStatus,
            'serverIpv4' => $serverIpv4,
            'serverIpv6' => $serverIpv6,
            'serverHostname' => $serverHostname,
        ]);
    }

    public function generateDkim(Request $request, string $domainName): Response
    {
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);

        if (null === $siteEntity) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        $this->checkCsrfToken($request, 'generate-dkim');

        $keyDir = self::DKIM_KEYS_DIR . '/' . $domainName;

        // All file operations use sudo because CloudPanel runs as 'clp' user
        // but opendkim keys are owned by 'opendkim' user
        $checkDir = trim(shell_exec(sprintf('sudo test -d %s && echo "exists" || echo "missing"', escapeshellarg($keyDir))));

        if ('exists' !== $checkDir) {
            $cmd = sprintf(
                'sudo mkdir -p %s && sudo opendkim-genkey -b 2048 -d %s -D %s -s %s 2>&1',
                escapeshellarg($keyDir),
                escapeshellarg($domainName),
                escapeshellarg($keyDir),
                escapeshellarg(self::DKIM_SELECTOR)
            );
            exec($cmd, $output, $returnCode);

            if (0 !== $returnCode) {
                $this->addFlash('error', 'DKIM key generation failed: ' . implode("\n", $output));
                return $this->redirect($this->generateUrl('clp_site_mail', ['domainName' => $domainName]));
            }

            // Fix permissions
            exec('sudo chown -R opendkim:opendkim ' . escapeshellarg($keyDir));
            exec(sprintf('sudo chmod 750 %s', escapeshellarg($keyDir)));
            exec(sprintf('sudo chmod 640 %s/%s.private', escapeshellarg($keyDir), self::DKIM_SELECTOR));

            // Add to signing table
            $signingEntry = sprintf(
                "*@%s         %s._domainkey.%s",
                $domainName,
                self::DKIM_SELECTOR,
                $domainName
            );
            $this->sudoAppendIfNotExists(self::DKIM_SIGNING_TABLE, $signingEntry, $domainName);

            // Add to key table
            $keyEntry = sprintf(
                "%s._domainkey.%s         %s:%s:%s/%s.private",
                self::DKIM_SELECTOR,
                $domainName,
                $domainName,
                self::DKIM_SELECTOR,
                $keyDir,
                self::DKIM_SELECTOR
            );
            $this->sudoAppendIfNotExists(self::DKIM_KEY_TABLE, $keyEntry, $domainName);

            // Restart OpenDKIM to pick up new key
            exec('sudo systemctl restart opendkim 2>&1');

            $this->addFlash('success', 'DKIM key generated successfully for ' . $domainName);
        } else {
            $this->addFlash('info', 'DKIM key already exists for ' . $domainName);
        }

        return $this->redirect($this->generateUrl('clp_site_mail', ['domainName' => $domainName]));
    }

    public function verifyDns(Request $request, string $domainName): JsonResponse
    {
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);

        if (null === $siteEntity) {
            return new JsonResponse(['error' => 'Site not found'], 404);
        }

        $status = [
            'spf' => $this->checkDnsRecord($domainName, 'TXT', 'v=spf1'),
            'dkim' => $this->checkDnsRecord(self::DKIM_SELECTOR . '._domainkey.' . $domainName, 'TXT', 'v=DKIM1'),
            'dmarc' => $this->checkDnsRecord('_dmarc.' . $domainName, 'TXT', 'v=DMARC1'),
        ];

        return new JsonResponse($status);
    }

    private function getDkimData(string $domainName): array
    {
        $keyDir = self::DKIM_KEYS_DIR . '/' . $domainName;
        $publicKeyFile = $keyDir . '/' . self::DKIM_SELECTOR . '.txt';

        // Use sudo to read because files are owned by opendkim
        $exists = trim(shell_exec(sprintf('sudo test -f %s && echo "yes" || echo "no"', escapeshellarg($publicKeyFile))));
        if ('yes' !== $exists) {
            return ['exists' => false, 'publicKey' => '', 'dnsRecord' => ''];
        }

        $rawContent = shell_exec(sprintf('sudo cat %s', escapeshellarg($publicKeyFile)));

        // Parse the DKIM TXT record - extract the p= value
        $dnsRecord = '';
        if (preg_match_all('/"([^"]*)"/', $rawContent, $matches)) {
            $dnsRecord = implode('', $matches[1]);
        }

        // Extract just the public key
        $publicKey = '';
        if (preg_match('/p=([A-Za-z0-9+\/=]+)/', $dnsRecord, $match)) {
            $publicKey = $match[1];
        }

        return [
            'exists' => true,
            'publicKey' => $publicKey,
            'dnsRecord' => $dnsRecord,
        ];
    }

    private function checkDnsRecord(string $hostname, string $type, string $contains): bool
    {
        $records = @dns_get_record($hostname, $type === 'TXT' ? DNS_TXT : DNS_A);

        if (false === $records || empty($records)) {
            return false;
        }

        foreach ($records as $record) {
            $txt = $record['txt'] ?? ($record['entries'][0] ?? '');
            if (false !== strpos($txt, $contains)) {
                return true;
            }
        }

        return false;
    }

    private function getServerIpv4(): string
    {
        $output = shell_exec("hostname -I 2>/dev/null | awk '{print $1}'");
        return trim($output ?: '');
    }

    private function getServerIpv6(): string
    {
        $output = shell_exec("ip -6 addr show scope global | grep -oP '(?<=inet6\s)[\da-f:]+' | head -1 2>/dev/null");
        return trim($output ?: '');
    }

    private function buildSpfRecord(string $ipv4, string $ipv6): string
    {
        $parts = ['v=spf1'];

        if (!empty($ipv4)) {
            $parts[] = 'ip4:' . $ipv4;
        }
        if (!empty($ipv6)) {
            $parts[] = 'ip6:' . $ipv6;
        }

        $parts[] = '-all';

        return implode(' ', $parts);
    }

    private function buildDmarcRecord(): string
    {
        return 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . (gethostname() ?: 'localhost');
    }

    private function sudoAppendIfNotExists(string $file, string $entry, string $domainName): void
    {
        $content = shell_exec(sprintf('sudo cat %s 2>/dev/null', escapeshellarg($file))) ?: '';

        if (false === strpos($content, $domainName)) {
            exec(sprintf(
                'echo %s | sudo tee -a %s > /dev/null',
                escapeshellarg($entry),
                escapeshellarg($file)
            ));
        }
    }
}
