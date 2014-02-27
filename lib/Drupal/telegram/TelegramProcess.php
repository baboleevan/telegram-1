<?php

/**
 * @file
 * Definition of Drupal/telegram/TelegramProcess
 */

namespace Drupal\telegram;

use \streamWrapper;

class TelegramProcess {

  // Running parameters.
  protected $commandLine;

  // Running process
  protected $process;

  // Pipes for input / output streams
  protected $pipes;

  // Input / Output history.
  protected $lastCommand;
  protected $output;
  protected $input;
  protected $logs;
  protected $errors;

  // Debug level
  protected $debug;

  /**
   * Class constructor.
   */
  public function __construct($command = '/usr/local/bin/telegram', $keyfile = '/etc/telegram/server.pub', $debug = FALSE) {
    $this->commandLine = $command . ' -k ' . $keyfile;
    $this->debug = $debug;
  }

  /**
   * Get proc status.
   *
   * @see proc_get_status()
   *
   * @return FALSE|array
   *   Status array if process is open, FALSE if not.
   */
  function getStatus() {
    if (isset($this->process)) {
      return proc_get_status($this->process);
    }
    else {
      return FALSE;
    }
  }

  /**
   * Low level exec function.
   */
  function execCommand($command, $params = '') {
    // Flush output ?
    $this->flush();
    // Run command.
    $this->lastCommand = $command;
    if ($params) {
      // @todo Better sanitize params.
      $params = $this->filter($params);
      $params = str_replace("\n", ' ');
      $command .= ' ' . $params;
    }
    // Read first line that should be the command.
    $this->write($command);
    $this->readUntil($command);
    $this->write("\n");

    // Get command response.
    return $this->getResponse();
  }

  /**
   * Get response.
   *
   * Cycle until response is got, wait for prompt.
   */
  function getResponse() {
    $this->output = NULL;
    while (!$this->output) {
      $response = $this->readUntil('>');
      $this->output = $response;
    }
    return $this->output;
  }

  /**
   * Parse response and return.
   */
  function parseResponse() {
    if (!isset($this->output)) {
      $this->getResponse();
    }
    return $this->output;
  }

  /**
   * Read multiple lines.
   */
  function read() {
    $this->debug('read');
    $string = stream_get_contents($this->pipes[1]);
    $string = $this->filter($string);
    $this->debug($string);
    return $string;
  }

  /**
   * Read single line.
   *
   * @param boolean $wait
   *   Whether to wait until it is available.
   */
  function readLine($wait = FALSE) {
    //$this->debug('readLine');

    $string = fgets($this->pipes[1]);

    while ($wait && $string === FALSE) {
      $string = fgets($this->pipes[1]);
      $this->wait();
    }

    if ($string !== FALSE) {
      $string = $this->filter($string);
    }

    return $string;
  }

  /**
   * Read until we find some (full line) string.
   *
   * @return array
   *   Array of (trimmed) string lines before the stop char.
   */
  function readUntil($stop = '>') {
    $this->debug("readUntil $stop");
    $stop = trim($stop);
    $lines = array();
    $string = '';
    while ($string !== $stop) {
      if ($string) {
        $lines[] = $string;
      }
      $string = $this->readLine(TRUE);
      $this->debug("readUntil [$string]");
    }
    return $lines;
  }

  /**
   * Low level write (adds line end).
   */
  function write($string) {
    $this->debug("writeString: $string");
    return fwrite($this->pipes[0], $string);
  }

  /**
   * Filter string, remove ANSI color codes.
   */
  function filter($string) {
    // Filter control codes in output
    //$string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $string);
    //$string = preg_replace("/#[a-f0-9]{6}/i", '', $string);
    //$string = preg_replace( '/[^[:print:]]/', '',$string);
    //$string = preg_replace('/[[:cntrl:]]+/', '', $string);
    //$string = preg_replace('/(?!\n)[\p{Cc}]/', '', $string);
    //$string = iconv("UTF-8", "UTF-8//IGNORE", $string);
    //sed -r "s/\x1B\[([0-9]{1,2}(;[0-9]{1,2})?)?[m|K]//g"
    //$string = preg_replace('/\x1B\[([0-9]{1,2}(;[0-9]{1,2})?)?/u', '', $string);
    $string = preg_replace('/\x1B\[[0-9;]*[mK]/u', '', $string);
    return trim($string);
  }

  /**
   * Flush stream before issuing a command.
   */
  function flush() {
    $this->debug('flush');
    return $this->read();
  }

  /**
   * Start process.
   */
  function start() {
    if (!isset($this->process)) {
      $this->pipes = array();
      $cwd = '/tmp';
      $descriptorspec = array(
         0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
         1 => array("pipe", "w+"),  // stdout is a pipe that the child will write to
         2 => array("pipe", "w+"),  // stderr
         //2 => array("file", '/tmp/telegram-error.txt', "a") // stderr is a file to write to
      );
      $this->process = proc_open($this->commandLine, $descriptorspec, $this->pipes, $cwd);

      if ($this->process) {
        // Use non blocking streams.
        stream_set_blocking($this->pipes[1], 0);
        stream_set_blocking($this->pipes[2], 0);
        // Flush initial messages.
        // $this->flush();

        $this->wait(1000);

        $this->flush();
        //$this->readUntil('>');

        if ($error = $this->getErrors()) {
          return FALSE;
        }
        else {
          $this->debug("Process started");
        }
      }
    }
    return is_resource($this->process);
  }

  /**
   * Exit process (send quit command).
   */
  function close() {
    if (isset($this->process)) {
      $this->debug("Closing");
      $this->write("quit\n");
      foreach ($this->pipes as $pipe) {
        fclose($pipe);
      };
      $return = proc_close($this->process);
      $this->debug("Return status: $return");
      unset($this->process);
      return $return;
    }
  }

  /**
   * Log line in output.
   */
  function debug($message) {
    //$this->output[] = $message;
    if ($this->debug) {
      print 'DEBUG: ' . $message . "\n";
    }
  }

  function wait($miliseconds = 10) {
    usleep(1000 * $miliseconds);
    $this->debug("Sleep $miliseconds ms");
  }

  /**
   * Get errors.
   */
  function getErrors() {
    if (isset($this->pipes) && is_resource($this->pipes[2])) {
      $this->debug("getErrors");
      while ($error = fgets($this->pipes[2])) {
        $this->debug("ERROR: $error");
        $this->errors[] = $error;
      }
    }
    return isset($this->errors) ? $this->errors : NULL;
  }

}