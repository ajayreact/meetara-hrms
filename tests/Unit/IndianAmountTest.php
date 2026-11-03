<?php

namespace Tests\Unit;

use App\Support\IndianAmount;
use PHPUnit\Framework\TestCase;

class IndianAmountTest extends TestCase
{
    public function test_indian_grouping_and_words_follow_lakhs_and_crores(): void
    {
        $this->assertSame('25,000', IndianAmount::figures(25000));
        $this->assertSame('Rupees Twenty-Five Thousand Only.', IndianAmount::words('25000'));
        $this->assertSame('3,00,000', IndianAmount::figures(300000));
        $this->assertSame('Rupees Three Lakh Only.', IndianAmount::words(300000));
        $this->assertSame('12,50,000', IndianAmount::figures(1250000));
        $this->assertSame('Rupees Twelve Lakh Fifty Thousand Only.', IndianAmount::words(1250000));
        $this->assertSame('1,00,00,000', IndianAmount::figures(10000000));
        $this->assertSame('Rupees One Crore Only.', IndianAmount::words(10000000));
    }
}
