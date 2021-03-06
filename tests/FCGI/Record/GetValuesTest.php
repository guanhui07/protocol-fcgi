<?php declare(strict_types=1);

namespace Lisachenko\Protocol\FCGI\Record;

use PHPUnit\Framework\TestCase;
use Lisachenko\Protocol\FCGI;

/**
 * @author Alexander.Lisachenko
 */
class GetValuesTest extends TestCase
{
    protected static $rawMessage = '01090000001107000f00464347495f4d5058535f434f4e4e5300000000000000';

    public function testPacking(): void
    {
        $request = new GetValues(array('FCGI_MPXS_CONNS'));
        $this->assertEquals(FCGI::GET_VALUES, $request->getType());
        $this->assertEquals(array('FCGI_MPXS_CONNS' => ''), $request->getValues());

        $this->assertSame(self::$rawMessage, bin2hex((string) $request));
    }

    public function testUnpacking(): void
    {
        $request = GetValues::unpack(hex2bin(self::$rawMessage));

        $this->assertEquals(FCGI::GET_VALUES, $request->getType());
        $this->assertEquals(array('FCGI_MPXS_CONNS' => ''), $request->getValues());
    }
}
