<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Unit;

use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Model\UploadDescriptor;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;
use Vadage\PresignedUploaderBundle\Validator\PresignedFileValidator;

/**
 * @extends ConstraintValidatorTestCase<PresignedFileValidator>
 */
final class PresignedFileValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidatorInterface
    {
        return new PresignedFileValidator();
    }

    public function testDefaultsToPresignGroup(): void
    {
        self::assertSame([PresignedFile::PRESIGN_GROUP], (new PresignedFile())->groups);
    }

    public function testValid(): void
    {
        $this->validator->validate(new UploadDescriptor('a.png', 100, 'image/png'), new PresignedFile(maxSize: '1k', mimeTypes: ['image/*'], extensions: ['png']));
        $this->validator->validate(null, new PresignedFile(maxSize: 1));

        $this->assertNoViolation();
    }

    public function testAcceptsStoredObjects(): void
    {
        $object = new StoredObject('s', 'k', 2000, 'image/png', 'a.png', null, new \DateTimeImmutable());
        $this->validator->validate($object, new PresignedFile(maxSize: '1k'));

        $this->buildViolation('The file is too large ({{ size }} {{ suffix }}). Allowed maximum size is {{ limit }} {{ suffix }}.')
            ->setParameters(['{{ size }}' => '2', '{{ limit }}' => '1', '{{ suffix }}' => 'kB'])
            ->setCode(PresignedFile::TOO_LARGE_ERROR)
            ->assertRaised();
    }

    public function testBinarySizes(): void
    {
        self::assertSame(5 * 1024 * 1024, (new PresignedFile(maxSize: '5Mi'))->maxSize);
        self::assertSame(5_000_000, (new PresignedFile(maxSize: '5M'))->maxSize);
    }

    public function testEmpty(): void
    {
        $this->validator->validate(new UploadDescriptor('a.png', 0, 'image/png'), new PresignedFile());

        $this->buildViolation('An empty file is not allowed.')->setCode(PresignedFile::EMPTY_ERROR)->assertRaised();
    }

    public function testMimeType(): void
    {
        $this->validator->validate(new UploadDescriptor('a.png', 10, 'text/html'), new PresignedFile(mimeTypes: ['image/png', 'image/jpeg']));

        $this->buildViolation('The mime type of the file is invalid ({{ type }}). Allowed mime types are {{ types }}.')
            ->setParameters(['{{ type }}' => '"text/html"', '{{ types }}' => '"image/png", "image/jpeg"'])
            ->setCode(PresignedFile::INVALID_MIME_TYPE_ERROR)
            ->assertRaised();
    }

    public function testExtension(): void
    {
        $this->validator->validate(new UploadDescriptor('a.exe', 10, 'image/png'), new PresignedFile(extensions: ['png']));

        $this->buildViolation('The extension of the file is invalid ({{ extension }}). Allowed extensions are {{ extensions }}.')
            ->setParameters(['{{ extension }}' => '"exe"', '{{ extensions }}' => '"png"'])
            ->setCode(PresignedFile::INVALID_EXTENSION_ERROR)
            ->assertRaised();
    }
}
