// Shared recorded invoice for grooming payments and transaction history.
window.PaymentInvoice = (() => {
  const currency = new Intl.NumberFormat("en-PH", {
    style: "currency", currency: "PHP", minimumFractionDigits: 2, maximumFractionDigits: 2,
  });
  const dateFormatter = new Intl.DateTimeFormat("en-PH", {
    month: "short", day: "numeric", year: "numeric",
  });
  const formatPeso = (amount) => currency.format(Number(amount || 0));
  return {
    formatPeso,
    formatDate(value) {
      const recordedValue = String(value ?? "").trim();
      const date = new Date(recordedValue);
      return Number.isNaN(date.getTime()) ? recordedValue : dateFormatter.format(date);
    },
    formatProductUnitPrice(line) {
      const quantity = Number(line.quantity);
      const subtotal = Number(line.subtotal);
      return Number.isFinite(quantity) && quantity > 0 && Number.isFinite(subtotal)
        ? formatPeso(subtotal / quantity) : "—";
    },
    print() {
      const printClass = "payment-invoice-printing";
      const invoice = document.getElementById("paymentInvoice");
      const printClone = invoice?.cloneNode(true) ?? null;
      let classRestored = false;

      function restorePrintClass() {
        if (classRestored) {
          return;
        }

        classRestored = true;
        printClone?.remove();
        document.body.classList.remove(printClass);
        window.removeEventListener("afterprint", restorePrintClass);
      }

      if (printClone) {
        // Keep the rendered payment snapshot outside Alpine's component lifecycle.
        printClone.setAttribute("x-ignore", "");
        printClone.classList.add("payment-invoice-print-clone");
        document.body.appendChild(printClone);
      }

      document.body.classList.add(printClass);
      window.addEventListener("afterprint", restorePrintClass);
      window.print();
      window.setTimeout(restorePrintClass, 1000);
    },
    template: `<section id="paymentInvoice">
  <header class="payment-invoice-header">
    <div class="payment-invoice-brand">
      <img src="../../assets/images/clinic/Bethlehem_Logo-256.png" alt="Bethlehem Animal Clinic logo" class="payment-invoice-logo" />
      <div><strong>BETHLEHEM</strong><span>ANIMAL CLINIC</span></div>
    </div>
    <div class="payment-invoice-heading">
      <h1>INVOICE</h1>
      <span class="payment-invoice-status">PAID</span>
    </div>
  </header>

  <dl class="payment-invoice-meta">
    <div><dt>Booking reference</dt><dd x-text="receiptModal.bookingReference"></dd></div>
    <div><dt>Invoice date</dt><dd x-text="formatInvoiceDate(receiptModal.paidAt)"></dd></div>
  </dl>

  <section class="payment-invoice-section payment-invoice-customer">
    <h2>Bill to</h2>
    <p class="payment-invoice-customer-name" x-text="receiptModal.ownerName"></p>
    <p x-show="receiptModal.contactNumber" x-text="receiptModal.contactNumber ? formatMobileNumber(receiptModal.contactNumber) : ''"></p>
  </section>

  <section class="payment-invoice-section">
    <h2>Grooming Services</h2>
    <template x-for="pet in receiptModal.pets" :key="'invoice-' + pet.id">
      <article class="payment-invoice-pet">
        <header class="payment-invoice-pet-header">
          <h3 x-text="pet.name"></h3>
          <p x-show="pet.species || pet.breed || pet.sizeLabel" x-text="[pet.species, pet.breed, pet.sizeLabel].filter(Boolean).join(' · ')"></p>
        </header>
        <table class="payment-invoice-table">
          <thead>
            <tr><th scope="col">Description</th><th scope="col">Qty</th><th scope="col">Unit price</th><th scope="col">Amount</th></tr>
          </thead>
          <tbody>
            <template x-for="line in pet.lines" :key="line.id">
              <tr>
                <td x-text="line.name"></td>
                <td>1</td>
                <td x-text="formatInvoicePeso(line.price)"></td>
                <td x-text="formatInvoicePeso(line.price)"></td>
              </tr>
            </template>
          </tbody>
        </table>
        <div x-show="pet.lines.length > 1" class="payment-invoice-category-total payment-invoice-pet-subtotal">
          <span>Subtotal</span><strong x-text="formatInvoicePeso(pet.subtotal)"></strong>
        </div>
      </article>
    </template>
    <div x-show="receiptModal.pets.length > 1" class="payment-invoice-category-total payment-invoice-grooming-total">
      <span>Grooming Services</span><strong x-text="formatInvoicePeso(receiptModal.groomingSubtotal)"></strong>
    </div>
  </section>

  <section x-show="receiptModal.products.length > 0" class="payment-invoice-section payment-invoice-products">
    <h2>Product Add-ons</h2>
    <table class="payment-invoice-table">
      <thead>
        <tr><th scope="col">Description</th><th scope="col">Qty</th><th scope="col">Unit price</th><th scope="col">Amount</th></tr>
      </thead>
      <tbody>
        <template x-for="(line, index) in receiptModal.products" :key="index">
          <tr>
            <td x-text="line.itemName"></td>
            <td x-text="line.quantity"></td>
            <td x-text="formatInvoiceProductUnitPrice(line)"></td>
            <td x-text="formatInvoicePeso(line.subtotal)"></td>
          </tr>
        </template>
      </tbody>
    </table>
    <div x-show="receiptModal.products.length > 1" class="payment-invoice-category-total payment-invoice-products-total">
      <span>Product Add-ons</span><strong x-text="formatInvoicePeso(receiptModal.productsSubtotal)"></strong>
    </div>
  </section>

  <dl class="payment-invoice-totals">
    <div><dt>Invoice total</dt><dd x-text="formatInvoicePeso(receiptModal.finalPrice)"></dd></div>
    <div><dt>Amount paid</dt><dd x-text="formatInvoicePeso(receiptModal.finalPrice)"></dd></div>
    <div><dt>Balance due</dt><dd x-text="formatInvoicePeso(0)"></dd></div>
  </dl>

  <section class="payment-invoice-section payment-invoice-details">
    <div class="payment-invoice-details-summary">
      <h2>Payment Details</h2>
      <dl>
        <div><dt>Payment method</dt><dd x-text="receiptModal.paymentMethod"></dd></div>
        <div x-show="receiptModal.paymentMethod?.toLowerCase() === 'cash'"><dt>Cash received</dt><dd x-text="formatInvoicePeso(receiptModal.amountPaid)"></dd></div>
        <div x-show="receiptModal.paymentMethod?.toLowerCase() === 'cash'"><dt>Change</dt><dd x-text="formatInvoicePeso(receiptModal.change)"></dd></div>
        <div><dt>Paid at</dt><dd x-text="receiptModal.paidAt"></dd></div>
      </dl>
    </div>
    <div x-show="receiptModal.notes" class="payment-invoice-notes">
      <p class="payment-invoice-notes-label">Notes</p>
      <p x-text="receiptModal.notes"></p>
    </div>
  </section>
</section>`,
  };
})();
