<?php

namespace Database\Seeders\Demo;

/**
 * Names, places and numbers for the sample data. Seeded with a fixed number, so every run of the demo seeders
 * produces the same people. Nothing here is a real person.
 */
final class DemoPeople
{
    private const MALE = ['Adebayo', 'Chukwuemeka', 'Ibrahim', 'Oluwaseun', 'Emeka', 'Tunde', 'Musa', 'Ifeanyi', 'Segun', 'Abdullahi', 'Kelechi', 'Femi',
        'Yusuf', 'Obinna', 'Damilare', 'Chinedu', 'Babatunde', 'Usman', 'Tobi', 'Nnamdi', 'Kayode', 'Sani', 'Uche', 'Gbenga', 'Ahmed', 'Ikenna', 'Wale', 'Bello'];
    private const FEMALE = ['Adaeze', 'Funmilayo', 'Aisha', 'Chiamaka', 'Folake', 'Ngozi', 'Zainab', 'Temitope', 'Amaka', 'Bukola', 'Hauwa', 'Ifeoma',
        'Yetunde', 'Chioma', 'Fatima', 'Omolara', 'Nkechi', 'Halima', 'Titilayo', 'Ebere', 'Kemi', 'Amina', 'Ronke', 'Uchenna', 'Bisola', 'Maryam', 'Ada', 'Simisola'];
    private const SURNAMES = ['Adeyemi', 'Okafor', 'Bello', 'Ogunleye', 'Eze', 'Abubakar', 'Okonkwo', 'Adebayo', 'Nwosu', 'Ibrahim', 'Olatunji', 'Chukwu',
        'Afolabi', 'Danjuma', 'Obi', 'Ajayi', 'Umeh', 'Lawal', 'Oyelaran', 'Nnaji', 'Akinola', 'Mohammed', 'Ojo', 'Onyekachi', 'Fashola', 'Garba', 'Akande',
        'Ekwueme', 'Babalola', 'Yakubu', 'Olaniyan', 'Anozie', 'Salami', 'Okeke', 'Adewale', 'Usman', 'Oladipo', 'Iwu', 'Aliyu', 'Ogundipe'];
    /** State => a few of its local government areas. */
    private const STATES = [
        'Ondo' => ['Akure South', 'Okitipupa', 'Ondo West', 'Owo', 'Ikare'], 'Ekiti' => ['Ado-Ekiti', 'Ikere', 'Ijero'], 'Osun' => ['Osogbo', 'Ife Central', 'Ilesa East'],
        'Oyo' => ['Ibadan North', 'Ogbomosho North', 'Oyo East'], 'Lagos' => ['Ikeja', 'Surulere', 'Alimosho', 'Epe'], 'Ogun' => ['Abeokuta South', 'Ijebu Ode', 'Sagamu'],
        'Edo' => ['Oredo', 'Esan West'], 'Delta' => ['Warri South', 'Oshimili South'], 'Enugu' => ['Enugu North', 'Nsukka'], 'Anambra' => ['Awka South', 'Onitsha North'],
        'Kwara' => ['Ilorin West', 'Offa'], 'Kogi' => ['Lokoja', 'Okene'], 'Kaduna' => ['Kaduna North', 'Zaria'], 'Kano' => ['Nassarawa', 'Fagge'],
        'Imo' => ['Owerri Municipal', 'Okigwe'], 'Rivers' => ['Port Harcourt', 'Obio/Akpor'], 'Benue' => ['Makurdi', 'Gboko'], 'FCT' => ['Abuja Municipal', 'Bwari'],
    ];

    private int $state;

    public function __construct(int $seed = 2026)
    {
        $this->state = $seed;
    }

    /** A number from $min to $max, the same sequence every run. */
    public function int(int $min, int $max): int
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $min + $this->state % ($max - $min + 1);
    }

    public function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    public function pick(array $list): mixed
    {
        $list = array_values($list);

        return $list[$this->int(0, count($list) - 1)];
    }

    /** @return array{first: string, last: string, other: string, gender: string} */
    public function person(?string $gender = null): array
    {
        $gender ??= $this->chance(50) ? 'female' : 'male';
        $pool = $gender === 'female' ? self::FEMALE : self::MALE;

        return ['first' => $this->pick($pool), 'last' => $this->pick(self::SURNAMES), 'other' => $this->pick($pool), 'gender' => $gender];
    }

    /** @return array{0: string, 1: string} [state, LGA] */
    public function origin(): array
    {
        $state = $this->pick(array_keys(self::STATES));

        return [$state, $this->pick(self::STATES[$state])];
    }

    public function phone(): string
    {
        return '0' . $this->pick(['803', '806', '813', '816', '703', '706', '805', '807', '815', '905', '810', '902']) . str_pad((string) $this->int(0, 9999999), 7, '0', STR_PAD_LEFT);
    }

    public function birthDate(int $age): string
    {
        return now()->subYears($age)->subDays($this->int(0, 360))->toDateString();
    }

    public function slug(string $first, string $last, int $n): string
    {
        return strtolower(preg_replace('/[^a-z]/i', '', $first) . '.' . preg_replace('/[^a-z]/i', '', $last)) . $n;
    }
}
