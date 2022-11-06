<?php

namespace App\Supports;

use App\Objects\CardDto;
use Freelancehunt\Validators\CreditCard;

trait CardValidate
{
    protected function formatDate($month, $year)
    {
        $year   = strlen($year) <= 2 ? '20' . $year : $year;
        $parse  = \Carbon\Carbon::parse('01-' . $month . '-' . $year);

        return $parse;
    }

    protected function parseCard(string $card, string $delimiter = '|'): CardDto
    {
        [$number, $month, $year, $csc] = str($card)->trim()->explode($delimiter);

        $parseCard = collect(CreditCard::validCreditCard(
            str($number)->replace(' ', '')->toString()
        ));

        $formatDate = $this->formatDate($month, $year);

        return new CardDto(
            number: $parseCard->get('number'),
            month: $formatDate->format('m'),
            year: $formatDate->format('Y'),
            securityCode: $csc,
            type: $parseCard->get('type')
        );
    }

    protected function validateRawCard(string $card, string $delimiter = '|')
    {
        $card = $this->parseCard($card, $delimiter);

        //
        $number = $card->number;
        $month = $card->month;
        $year = $card->year;
        $securityCode = $card->securityCode;

        [
            'valid' => $valid,
            'type'  =>  $type
        ] = CreditCard::validCreditCard($number);

        if (!$valid) {
            return false;
        }

        $validDate = CreditCard::validDate($year, $month);
        if (!$validDate) {
            return false;
        }

        if (!CreditCard::validCvc($securityCode, $type)) {
            return false;
        }

        return true;
    }
}
