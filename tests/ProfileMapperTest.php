<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Tests;

use Kkxdev\Truecaller\Exceptions\ProfileIncompleteException;
use Kkxdev\Truecaller\Support\PhoneNormaliser;
use Kkxdev\Truecaller\Support\ProfileMapper;
use PHPUnit\Framework\Attributes\DataProvider;

class ProfileMapperTest extends TestCase
{
    private ProfileMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        [$isoMap, $rules] = $this->shippedPhoneConfig();

        $this->mapper = new ProfileMapper(new PhoneNormaliser($isoMap, $rules));
    }

    /**
     * @return array<string, mixed>
     */
    private function userInfo(array $overrides = []): array
    {
        return $overrides + [
            'sub'                       => 'tc-sub-1',
            'given_name'                => 'Asha',
            'family_name'               => 'Verma',
            'phone_number'              => '+919876543210',
            'phone_number_country_code' => 'IN',
            'email'                     => 'asha@example.com',
            'email_verified'            => true,
        ];
    }

    public function testMapsUserinfoIntoAProfileWithNameEmailAndPhone(): void
    {
        $profile = $this->mapper->map($this->userInfo());

        $this->assertSame('tc-sub-1', $profile->sub());
        $this->assertSame('Asha', $profile->firstName());
        $this->assertSame('Verma', $profile->lastName());
        $this->assertSame('Asha Verma', $profile->fullName());
        $this->assertSame('asha@example.com', $profile->email());
        $this->assertTrue($profile->emailVerified());
        $this->assertSame('+919876543210', $profile->phone()->e164());
        $this->assertSame('IN', $profile->isoCountry());
    }

    public function testHandlesNestedNameShape(): void
    {
        $info = $this->userInfo();
        unset($info['given_name'], $info['family_name']);
        $info['name'] = ['first' => 'Asha', 'last' => 'Verma'];

        $profile = $this->mapper->map($info);

        $this->assertSame('Asha', $profile->firstName());
        $this->assertSame('Verma', $profile->lastName());
    }

    public function testHiddenNameLeavesTheProfileUsable(): void
    {
        // Truecaller users can hide their name; only the phone is required.
        $info = $this->userInfo();
        unset($info['given_name'], $info['family_name']);

        $profile = $this->mapper->map($info);

        $this->assertNull($profile->fullName());
        $this->assertSame('+919876543210', $profile->phone()->e164());
    }

    /**
     * @dataProvider unsetGenderSentinels
     */
    #[DataProvider('unsetGenderSentinels')]
    public function testUnsetGenderSentinelIsMappedToNull(string $sentinel): void
    {
        // Storing the sentinel would record "NotSet" as if it were an answer.
        $profile = $this->mapper->map($this->userInfo(['gender' => $sentinel]));

        $this->assertNull($profile->gender());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsetGenderSentinels(): array
    {
        return ['documented' => ['NotSet'], 'as seen in the wild' => ['NonSet']];
    }

    public function testRealGenderIsKept(): void
    {
        $this->assertSame('Female', $this->mapper->map($this->userInfo(['gender' => 'Female']))->gender());
    }

    public function testUnverifiedEmailIsCarriedButFlaggedAsUnverified(): void
    {
        $profile = $this->mapper->map($this->userInfo(['email_verified' => false]));

        $this->assertFalse($profile->emailVerified());
    }

    public function testMalformedEmailIsDiscardedRatherThanStored(): void
    {
        $this->assertNull($this->mapper->map($this->userInfo(['email' => 'not-an-email']))->email());
    }

    public function testMissingSubjectIsRejected(): void
    {
        // A profile with no subject is a broken contract, not a user to trust.
        $info = $this->userInfo();
        unset($info['sub']);

        $this->expectException(ProfileIncompleteException::class);

        $this->mapper->map($info);
    }

    public function testUserinfoWithoutAPhoneNumberIsRejected(): void
    {
        // Happens when the phone scope was not granted. Never fall back to the
        // email as an identity: that is how two people become one account.
        $info = $this->userInfo();
        unset($info['phone_number']);

        $this->expectException(ProfileIncompleteException::class);

        $this->mapper->map($info);
    }

    public function testIdentityForPersistenceCarriesOnlyTheSubject(): void
    {
        // The access token is single-use; storing it would be a liability.
        $this->assertSame(['sub' => 'tc-sub-1'], $this->mapper->map($this->userInfo())->identityForPersistence());
    }
}
