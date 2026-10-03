<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Organisation;
use Base\Ledger\Entity\Book;
use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Report\IncomeStatement;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\EntryLineRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The company's accounts as its associates read them (omnibase/ledger, through the
 * organisation's book): the year's income statement so far and the year before, each
 * associate's share of the result - an SCI is transparent: each declares their share (2072,
 * then their own return) -, the bank accounts' balances and their latest movements. Only
 * loaded with omnibase/ledger.
 */
final class CompanyAccounts
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncomeStatement $income,
        private readonly BankAccountRepository $bankAccounts,
        private readonly BankLineRepository $bankLines,
        private readonly EntryLineRepository $entryLines,
    ) {
    }

    /**
     * @param list<Associate> $associates
     *
     * @return array{book: Book, year: int, statement: array, shares: list<array{associate: Associate, share: int, rate: float}>, banks: list<array{account: \Base\Ledger\Entity\BankAccount, balance: int, lines: list<\Base\Ledger\Entity\BankLine>}>}|null
     */
    public function of(Organisation $organisation, array $associates, ?\DateTimeImmutable $today = null): ?array
    {
        $book = $organisation->getBookId() ? $this->entityManager->find(Book::class, $organisation->getBookId()) : null;
        if (!$book instanceof Book) {
            return null;
        }
        $today ??= new \DateTimeImmutable('today');
        $statement = $this->income->compute($book, $today->modify('first day of january this year'), $today, true);
        $result = $statement['totals']['result'];
        $total = array_sum(array_map(fn (Associate $a) => $a->getShares(), $associates));

        $banks = [];
        foreach ($this->bankAccounts->forBook($book) as $account) {
            $banks[] = [
                'account' => $account,
                'balance' => $this->entryLines->balance($account->getAccount()),
                'lines' => array_slice(array_values(array_filter($this->bankLines->latest($account, 30), fn ($line) => BankLineStatus::IGNORED !== $line->getStatus())), 0, 8),
            ];
        }

        return [
            'book' => $book,
            'year' => (int) $today->format('Y'),
            'statement' => $statement,
            'shares' => array_map(fn (Associate $a) => [
                'associate' => $a,
                'rate' => $total ? $a->getShares() / $total : 0.0,
                'share' => $total ? (int) round($result * $a->getShares() / $total) : 0,
            ], $associates),
            'banks' => $banks,
        ];
    }
}
