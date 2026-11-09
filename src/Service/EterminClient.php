<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Zugriff auf die eTermin-API (https://www.etermin.net/api/).
 *
 * Die Signatur wird pro Anfrage aus einem zufälligen Salt und dem Secret Key berechnet
 * (base64(HMAC-SHA256(salt, secretKey))).
 */
class EterminClient
{
    private const BASE_URL = 'https://www.etermin.net/api/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'ETERMIN_PUBLIC_KEY')]
        private readonly string $publicKey,
        #[Autowire(env: 'ETERMIN_SECRET_KEY')]
        private readonly string $secretKey,
        #[Autowire(env: 'ETERMIN_SERVICE_ID')]
        private readonly string $serviceId,
        #[Autowire(env: 'ETERMIN_CALENDAR_ID')]
        private readonly string $calendarId,
    ) {
    }

    /**
     * Tage mit freien Terminen im Zeitraum.
     *
     * @return list<string> Datumsangaben im Format Y-m-d
     */
    public function getAvailableDays(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $days = $this->request('GET', 'timeslots', ['query' => [
            'date' => $from->format('Y-m-d'),
            'rangesearch' => 1,
            'end' => $to->format('Y-m-d'),
            'serviceid' => $this->serviceId,
        ]]);

        $result = [];
        foreach ($days as $day) {
            if (($day['available'] ?? 1) > 0 && isset($day['start'])) {
                $result[] = \substr($day['start'], 0, 10);
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * Freie Zeitfenster eines Tages.
     *
     * @return list<array{start: string, end: string, calendarid: int|string}>
     */
    public function getDaySlots(\DateTimeInterface $date): array
    {
        $slots = $this->request('GET', 'timeslots', ['query' => [
            'date' => $date->format('Y-m-d'),
            'rangesearch' => 0,
            'serviceid' => $this->serviceId,
        ]]);

        return \array_values(\array_filter($slots, static fn ($slot) => isset($slot['start'], $slot['end'])));
    }

    /**
     * Sucht ein freies Zeitfenster anhand seines Starts (Y-m-d\TH:i).
     *
     * @return array{start: string, end: string, calendarid: int|string}|null
     */
    public function findSlot(\DateTimeInterface $start): ?array
    {
        foreach ($this->getDaySlots($start) as $slot) {
            if (\substr($slot['start'], 0, 16) === $start->format('Y-m-d\TH:i')) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Bucht einen Termin. Liefert true, wenn eTermin den Termin angelegt hat.
     *
     * @param array{start: string, end: string, calendarid: int|string} $slot
     * @param array<string, string> $patient
     */
    public function bookAppointment(array $slot, array $patient): bool
    {
        $response = $this->request('POST', 'appointment', ['body' => [
            'start' => (new \DateTimeImmutable($slot['start']))->format('Y-m-d H:i'),
            'end' => (new \DateTimeImmutable($slot['end']))->format('Y-m-d H:i'),
            'calendarid' => $slot['calendarid'] ?: $this->calendarId,
            'services' => $this->serviceId,
            'salutation' => $patient['salutation'],
            'firstname' => $patient['firstname'],
            'lastname' => $patient['lastname'],
            'email' => $patient['email'],
            'phone' => $patient['phone'],
            'notes' => $patient['notes'],
            'street' => $patient['street'],
            'zip' => $patient['zip'],
            'city' => $patient['city'],
            'birthday' => $patient['birthday'],
            'sendemail' => 'true',
            'notificationmsg' => 'true',
            'appreminder' => $patient['appreminder'],
            'additional2' => 'MVZ',
        ]]);

        // eTermin antwortet bei Erfolg mit dem angelegten Termin als Objekt, bei Fehlern mit einer Liste.
        return [] !== $response && !\array_is_list($response);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     *
     * @throws EterminException
     */
    private function request(string $method, string $path, array $options): array
    {
        $salt = \bin2hex(\random_bytes(16));
        $options['headers'] = [
            'publickey' => $this->publicKey,
            'salt' => $salt,
            'signature' => \base64_encode(\hash_hmac('sha256', $salt, $this->secretKey, true)),
            'Accept' => 'application/json',
        ];
        $options['timeout'] = 15;

        try {
            $response = $this->httpClient->request($method, self::BASE_URL . $path, $options);

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new EterminException('eTermin-Anfrage fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }
}
