<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * AppFixtures
 *
 * Base Doctrine data fixture class for application testing and initialization.
 */
class AppFixtures extends Fixture
{
    /**
     * Loads fixture data into the object manager.
     *
     * @param ObjectManager $manager Doctrine object manager.
     * @return void
     */
    public function load(ObjectManager $manager): void
    {
        $manager->flush();
    }
}
