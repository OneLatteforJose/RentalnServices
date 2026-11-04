<?php
require_once __DIR__ . '/includes/site.php';

$vehicles = vehicle_types();
$services = service_categories();
$heroServices = array_slice($services, 0, 5);

public_header('Vehicles and home services', true);
?>
<section class="hero">
  <div class="wrap">
    <div>
      <h1>Rent a vehicle. Book a helper. Handled in your area.</h1>
      <p class="lead">Choose a car, van or truck with or without a driver, or request plumbing, cleaning, massage and more. A manager in your area confirms every booking, and you pay online.</p>
      <div class="actions">
        <a class="btn btn-signal" href="#login">Log in to book</a>
        <a class="btn btn-ghost" href="#vehicles">See what you can book</a>
      </div>
    </div>

    <div class="passes">
      <article class="pass">
        <div class="stub">Rent</div>
        <div class="body">
          <h2>Book a vehicle</h2>
          <p>Cars, vans and trucks, with or without a driver.</p>
          <ul class="chips">
            <?php foreach ($vehicles as [$label]): ?><li><?= e($label) ?></li><?php endforeach; ?>
          </ul>
          <a class="more" href="#login">Log in to book a vehicle</a>
        </div>
      </article>
      <article class="pass">
        <div class="stub">Book</div>
        <div class="body">
          <h2>Request a service</h2>
          <p>Local providers for your home and personal needs.</p>
          <ul class="chips">
            <?php foreach ($heroServices as [$label]): ?><li><?= e($label) ?></li><?php endforeach; ?>
            <li>and more</li>
          </ul>
          <a class="more" href="#login">Log in to request a service</a>
        </div>
      </article>
    </div>
  </div>
</section>

<section class="section" id="vehicles">
  <div class="wrap">
    <h2>Vehicles for rent</h2>
    <p class="intro">Pick the vehicle that fits the trip, then decide whether you want to drive it yourself.</p>
    <ul class="rows">
      <?php foreach ($vehicles as [$label, $desc]): ?>
        <li>
          <strong><?= e($label) ?></strong>
          <span><?= e($desc) ?></span>
          <span class="tags"><span class="tag">Drive yourself</span><span class="tag">With a driver</span></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="note">A driver comes from the vehicle owner, or your area manager assigns one.</p>
  </div>
</section>

<section class="section" id="services">
  <div class="wrap">
    <h2>Services at your door</h2>
    <p class="intro">Request a service from providers who work in your area.</p>
    <ul class="svc">
      <?php foreach ($services as [$label, $desc]): ?>
        <li><strong><?= e($label) ?></strong><span><?= e($desc) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="section" id="how">
  <div class="wrap">
    <h2>How it works</h2>
    <p class="intro">From request to review in four steps.</p>
    <ol class="steps">
      <li><strong>Create an account</strong><span>Sign up, then choose to book a vehicle or request a service.</span></li>
      <li><strong>Pick a time and pay</strong><span>We check that the vehicle or provider is available. You pay online.</span></li>
      <li><strong>Your manager approves</strong><span>The manager for your area reviews and approves your booking or request.</span></li>
      <li><strong>Enjoy, then review</strong><span>Once it is done, rate the vehicle or service.</span></li>
    </ol>
  </div>
</section>

<section class="section" id="area">
  <div class="wrap">
    <h2>Built around your area</h2>
    <div class="triple">
      <div><h3>Local managers</h3><p>Every booking and request is reviewed by a manager who covers your area.</p></div>
      <div><h3>Recommended providers</h3><p>Providers are recommended by area managers and activated by the admin.</p></div>
      <div><h3>Honest ratings</h3><p>Only people who actually used a vehicle or service can review it.</p></div>
    </div>
  </div>
</section>

<section class="section" id="partners">
  <div class="wrap">
    <div class="callout">
      <h2>Own a vehicle or offer a service?</h2>
      <p>Providers join through a recommendation from the manager of their area. Once the admin activates your account, you can list your vehicles and services and start receiving bookings.</p>
    </div>
  </div>
</section>

<section class="section" id="login">
  <div class="wrap loginwrap">
    <div>
      <h2>Log in</h2>
      <p class="intro">Use your account to book a vehicle, request a service or follow your bookings.</p>
    </div>
    <?php login_form(); ?>
  </div>
</section>
<?php public_footer();
