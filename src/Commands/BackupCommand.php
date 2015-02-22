<?php namespace Spatie\Backup\Commands;
use Illuminate\Console\Command;
use Exception;
use Spatie\Backup\BackupHandlers\Database\DatabaseBackupHandler;
use Spatie\Backup\BackupHandlers\Files\FilesBackupHandler;
use Storage;
use ZipArchive;

class BackupCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'backup:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the backup';

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws Exception
     */
    public function fire()
    {
        $this->info('Start backing up');

        $files = $this->getAllFilesToBeBackedUp();

        $backupZipFile = $this->createZip($files);

        foreach($this->getTargetFileSystems() as $fileSystem)
        {
            $disk = Storage::disk($fileSystem);

            $this->copyFile($backupZipFile, $disk, $this->getBackupDestinationFileName(), $fileSystem == 'local');

            $this->comment('Database successfully backupped on ' . $fileSystem . '-filesystem in file ' . $this->getBackupDestinationFileName());
        }

        $this->info('Backup successfully completed');

    }


    /**
     * Copy the given file on the given disk to the given destination
     *
     * @param string $file
     * @param \Illuminate\Contracts\Filesystem\Filesystem $disk
     * @param string $destination
     * @param bool $addIgnoreFile
     */
    protected function copyFile($file, $disk, $destination, $addIgnoreFile = false)
    {
        $destionationDirectory = dirname($destination);

        $disk->makeDirectory($destionationDirectory);

        if ($addIgnoreFile)
        {
            $this->writeIgnoreFile($disk, $destionationDirectory);
        }

        $disk->getDriver()->writeStream($destination, fopen($file, 'r+'));
    }

    /**
     * Get the filesystems to where the database should be dumped
     *
     * @return array
     */
    protected function getTargetFileSystems()
    {
        $fileSystems = config('laravel-backup.destination.filesystem');

        if (is_array($fileSystems))
        {
            return $fileSystems;
        }

        return [$fileSystems];

    }

    /**
     * Write an ignore-file on the given disk in the given directory
     *
     * @param \Illuminate\Contracts\Filesystem\Filesystem $disk
     * @param string $dumpDirectory
     */
    protected function writeIgnoreFile($disk, $dumpDirectory)
    {
        $gitIgnoreContents = '*' . PHP_EOL . '!.gitignore';
        $disk->put($dumpDirectory . '/.gitignore', $gitIgnoreContents);
    }

    /**
     * Return an array with path to files that should be backed up
     *
     * @return array
     */
    private function getAllFilesToBeBackedUp()
    {
        $this->comment('Determining which files should be backed up');

        $files = [];

        $databaseBackupHandler = app()->make(DatabaseBackupHandler::class);
        foreach($databaseBackupHandler->getFilesToBeBackedUp() as $file)
        {
            $files[] = ['realFile' => $file, 'fileInZip' => 'db/dump.sql'];
        }
        $this->info('Database dumped');

        $fileBackupHandler = app()->make(FilesBackupHandler::class)
            ->setIncludedFiles(config('laravel-backup.source.files.include'))
            ->setExcludedFiles(config('laravel-backup.source.files.exclude'));
        foreach($fileBackupHandler->getFilesToBeBackedUp() as $file)
        {
            $files[] = ['realFile' => $file, 'fileInZip' => 'files/' . $file];
        }

        return $files;
    }

    /**
     * Create a zip for the given files
     *
     * @param $files
     * @return string
     */
    public function createZip($files)
    {
        $this->comment('Start zipping ' . count($files) . ' files');

        $tempZipFile = tempnam(sys_get_temp_dir(), "laravel-backup-zip");

        $zip = new ZipArchive();
        $zip->open($tempZipFile, ZipArchive::CREATE);

        foreach($files as $file)
        {
            if (file_exists($file['realFile']))
            {
                $zip->addFile($file['realFile'], $file['fileInZip']);
            }

        }

        $zip->close();

        $this->comment('Created zip file containing all files that need to be backed up');

        return $tempZipFile;
    }

    /**
     * Determine the name of the zip that contains the backup
     *
     * @return string
     */
    private function getBackupDestinationFileName()
    {
        return config('laravel-backup.destination.path') . '/' . date('YmdHis') . '.zip' ;
    }
}
