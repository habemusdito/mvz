<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\EterminClient;
use App\Service\EterminException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Online-Terminbuchung für die Blutentnahme über eTermin.
 */
#[Route('/blutentnahme', name: 'etermin_')]
class Etermin extends AbstractController
{
    /** Wie viele Tage im Voraus Termine angeboten werden. */
    private const DAYS_AHEAD = 30;

    private const TIMEZONE = 'Europe/Berlin';

    public const REMINDER_OPTIONS = [
        '0' => 'Keine Terminerinnerung',
        '-1' => '1 Stunde vor Termin',
        '-2' => '2 Stunden vor Termin',
        '-3' => '3 Stunden vor Termin',
        '-4' => '4 Stunden vor Termin',
        '-8' => '8 Stunden vor Termin',
        '-12' => '12 Stunden vor Termin',
        '-24' => '24 Stunden vor Termin',
    ];

    public function __construct(
        private readonly EterminClient $etermin,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE));

        return $this->render('pages/blutentnahme.html.twig', [
            'minDate' => $today,
            'maxDate' => $today->modify('+' . self::DAYS_AHEAD . ' days'),
        ]);
    }

    #[Route('/tage', name: 'days', methods: ['GET'])]
    public function days(): JsonResponse
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE));

        try {
            $days = $this->etermin->getAvailableDays($today, $today->modify('+' . self::DAYS_AHEAD . ' days'));
        } catch (EterminException $e) {
            $this->logger->error($e->getMessage());

            return new JsonResponse(['error' => 'Die freien Termine konnten nicht geladen werden.'], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse($days);
    }

    #[Route('/slots/{date}', name: 'slots', requirements: ['date' => '\d{4}-\d{2}-\d{2}'], methods: ['GET'])]
    public function slots(string $date): JsonResponse
    {
        $day = $this->parseDate('Y-m-d', $date);
        if (null === $day) {
            return new JsonResponse(['error' => 'Ungültiges Datum.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $slots = $this->etermin->getDaySlots($day);
        } catch (EterminException $e) {
            $this->logger->error($e->getMessage());

            return new JsonResponse(['error' => 'Die freien Termine konnten nicht geladen werden.'], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(\array_map(fn (array $slot) => [
            'time' => \substr($slot['start'], 11, 5),
            'url' => $this->generateUrl('etermin_book', ['start' => \substr($slot['start'], 0, 16)]),
        ], $slots));
    }

    #[Route('/termin/{start}', name: 'book', requirements: ['start' => '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}'], methods: ['GET', 'POST'])]
    public function book(string $start, Request $request, ValidatorInterface $validator): Response
    {
        $startDate = $this->parseDate('Y-m-d\TH:i', $start);
        if (null === $startDate) {
            throw $this->createNotFoundException();
        }

        $data = $this->getFormData($request);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('etermin', (string) $request->request->get('_token'))) {
                $errors['_form'] = 'Die Sitzung ist abgelaufen. Bitte senden Sie das Formular erneut ab.';
            }

            foreach ($validator->validate($data, $this->getConstraints()) as $violation) {
                $field = \trim($violation->getPropertyPath(), '[]');
                $errors[$field] ??= (string) $violation->getMessage();
            }

            if (!isset($errors['gebday']) && !isset($errors['gebmonth']) && !isset($errors['gebyear'])
                && !\checkdate((int) $data['gebmonth'], (int) $data['gebday'], (int) $data['gebyear'])
            ) {
                $errors['gebday'] = 'Bitte geben Sie ein gültiges Geburtsdatum an.';
            }

            if ([] === $errors) {
                return $this->bookSlot($startDate, $data);
            }
        }

        return $this->render('pages/blutentnahme-termin.html.twig', [
            'start' => $startDate,
            'data' => $data,
            'errors' => $errors,
            'reminderOptions' => self::REMINDER_OPTIONS,
        ]);
    }

    /**
     * @param array<string, string> $data
     */
    private function bookSlot(\DateTimeImmutable $start, array $data): Response
    {
        try {
            // Das Zeitfenster erneut bei eTermin abfragen, damit nur tatsächlich freie Termine gebucht werden.
            $slot = $this->etermin->findSlot($start);

            if (null === $slot) {
                $this->addFlash('blutentnahme_error', 'Dieser Termin ist leider nicht mehr frei. Bitte wählen Sie einen anderen Termin.');

                return $this->redirectToRoute('etermin_index');
            }

            $booked = $this->etermin->bookAppointment($slot, [
                'salutation' => $data['Salutation'],
                'firstname' => $data['FirstName'],
                'lastname' => $data['LastName'],
                'email' => $data['Email'],
                'phone' => $data['Phone'],
                'notes' => $data['Notes'],
                'street' => $data['Street'],
                'zip' => $data['ZIP'],
                'city' => $data['City'],
                'birthday' => \sprintf('%04d-%02d-%02d', $data['gebyear'], $data['gebmonth'], $data['gebday']),
                'appreminder' => $data['AppReminder'],
            ]);
        } catch (EterminException $e) {
            $this->logger->error($e->getMessage());
            $booked = false;
        }

        if ($booked) {
            $this->addFlash('blutentnahme_success', 'Vielen Dank, Ihr Blutentnahme-Termin wurde gespeichert. Wir haben Ihnen eine Bestätigungs-E-Mail geschickt.');
        } else {
            $this->addFlash('blutentnahme_error', 'Ihr Termin konnte leider nicht gespeichert werden. Bitte versuchen Sie es erneut.');
        }

        return $this->redirectToRoute('etermin_index');
    }

    /**
     * Liest ein Datum streng ein: ungültige Werte wie 2026-13-45 werden nicht umgerechnet, sondern abgelehnt.
     */
    private function parseDate(string $format, string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone(self::TIMEZONE));

        return false !== $date && $date->format($format) === $value ? $date : null;
    }

    /**
     * @return array<string, string>
     */
    private function getFormData(Request $request): array
    {
        $fields = ['Salutation', 'FirstName', 'LastName', 'gebday', 'gebmonth', 'gebyear', 'Street', 'ZIP', 'City', 'Phone', 'Email', 'Notes', 'AppReminder'];

        $data = [];
        foreach ($fields as $field) {
            $data[$field] = \trim((string) $request->request->get($field, ''));
        }
        $data['AppReminder'] = '' === $data['AppReminder'] ? '0' : $data['AppReminder'];

        return $data;
    }

    private function getConstraints(): Assert\Collection
    {
        $required = static fn (string $message) => new Assert\NotBlank(message: $message);

        return new Assert\Collection([
            'Salutation' => [$required('Bitte wählen Sie eine Anrede.'), new Assert\Choice(choices: ['Frau', 'Herr', 'Divers'], message: 'Bitte wählen Sie eine Anrede.')],
            'FirstName' => [$required('Bitte geben Sie Ihren Vornamen an.'), new Assert\Length(max: 100)],
            'LastName' => [$required('Bitte geben Sie Ihren Nachnamen an.'), new Assert\Length(max: 100)],
            'gebday' => [$required('Bitte geben Sie Ihr Geburtsdatum an.'), new Assert\Regex(pattern: '/^\d{1,2}$/', message: 'Bitte geben Sie den Tag als Zahl an.')],
            'gebmonth' => [$required('Bitte geben Sie Ihr Geburtsdatum an.'), new Assert\Regex(pattern: '/^\d{1,2}$/', message: 'Bitte geben Sie den Monat als Zahl an.')],
            'gebyear' => [$required('Bitte geben Sie Ihr Geburtsdatum an.'), new Assert\Regex(pattern: '/^(19|20)\d{2}$/', message: 'Bitte geben Sie das Jahr vierstellig an.')],
            'Street' => [$required('Bitte geben Sie Ihre Straße an.'), new Assert\Length(max: 150)],
            'ZIP' => [$required('Bitte geben Sie Ihre PLZ an.'), new Assert\Regex(pattern: '/^\d{4,5}$/', message: 'Bitte geben Sie eine gültige PLZ an.')],
            'City' => [$required('Bitte geben Sie Ihren Ort an.'), new Assert\Length(max: 100)],
            'Phone' => [$required('Bitte geben Sie Ihre Telefonnummer an.'), new Assert\Length(max: 50)],
            'Email' => [$required('Bitte geben Sie Ihre E-Mail-Adresse an.'), new Assert\Email(message: 'Bitte geben Sie eine gültige E-Mail-Adresse an.')],
            'Notes' => [new Assert\Length(max: 1000)],
            'AppReminder' => [new Assert\Choice(choices: \array_map('strval', \array_keys(self::REMINDER_OPTIONS)), message: 'Bitte wählen Sie eine Terminerinnerung.')],
        ]);
    }
}
