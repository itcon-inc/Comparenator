<?php

namespace Drupal\Tests\comparenator\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\comparenator\Form\RefreshForm;
use Drupal\comparenator\Service\ScreenshotManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Verifies forced capture and explicit form feedback.
 */
class RefreshFormTest extends TestCase {

  public function testRefreshForcesBothImagesAndPreservesDestination(): void {
    $parameters = ['identifier' => 'frontpage', 'size' => 'laptop', 'reference' => 'prod', 'current' => 'local'];
    $screenshots = $this->createMock(ScreenshotManager::class);
    $screenshots->expects($this->once())->method('ensureComparison')
      ->with('frontpage', 'laptop', 'prod', 'local', '/', TRUE)->willReturn(['/ref.png', '/cur.png']);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');
    $messenger->expects($this->never())->method('addError');
    $form = $this->form($screenshots, $messenger);
    $state = new FormState();
    $build = $form->buildForm([], $state, $parameters, '/', 'side');
    $this->assertSame('submit', $build['actions']['refresh']['#type']);
    $form->submitForm($build, $state);
    $redirect = $state->getRedirect();
    $this->assertSame('comparenator.environment_compare', $redirect->getRouteName());
    $this->assertSame($parameters, $redirect->getRouteParameters());
    $this->assertSame(['path' => '/', 'mode' => 'side'], $redirect->getOption('query'));
  }

  public function testRefreshFailureIsNotReportedAsSuccess(): void {
    $screenshots = $this->createMock(ScreenshotManager::class);
    $screenshots->method('ensureComparison')->willThrowException(new \RuntimeException('Browser unavailable'));
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addStatus');
    $messenger->expects($this->once())->method('addError');
    $form = $this->form($screenshots, $messenger);
    $state = new FormState();
    $build = $form->buildForm([], $state, ['identifier' => 'frontpage', 'size' => 'laptop', 'reference' => 'prod', 'current' => 'local']);
    $form->submitForm($build, $state);
  }

  public function testAnonymousUsersCannotForceRefresh(): void {
    $screenshots = $this->createMock(ScreenshotManager::class);
    $screenshots->expects($this->never())->method('ensureComparison');
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn(FALSE);
    $form = new RefreshForm($screenshots, new NullLogger(), $account);
    $translation = $this->createMock(TranslationInterface::class);
    $form->setStringTranslation($translation);
    $state = new FormState();
    $build = $form->buildForm([], $state);
    $this->assertFalse($build['#access']);
    $this->expectException(AccessDeniedHttpException::class);
    $form->submitForm($build, $state);
  }

  private function form(ScreenshotManager $screenshots, MessengerInterface $messenger): RefreshForm {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->with('view environment comparisons')->willReturn(TRUE);
    $form = new RefreshForm($screenshots, new NullLogger(), $account);
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnArgument(0);
    $form->setStringTranslation($translation);
    $form->setMessenger($messenger);
    return $form;
  }

}
