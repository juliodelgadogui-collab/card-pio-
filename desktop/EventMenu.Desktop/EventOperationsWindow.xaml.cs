using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class EventOperationsWindow : Window
{
    private readonly OperationalActionsApiClient _api;
    private readonly EventMenuApiClient _mainApi;
    private readonly bool _canEvents;
    private readonly bool _canTickets;
    private readonly bool _canGuests;
    private readonly bool _canEventBar;
    private readonly bool _canPayments;
    private readonly bool _canCash;
    private readonly bool _canViewRecent;
    private readonly ObservableCollection<CartLine> _barCart = new();
    private List<EventOverviewItem> _events = new();
    private List<Product> _catalog = new();
    private EventPickupOrder? _pickupOrder;
    private string _pickupCode = "";
    private bool _loading;

    public bool OperationChanged { get; private set; }

    public EventOperationsWindow(
        SecureSessionStore store,
        bool canEvents,
        bool canTickets,
        bool canGuests,
        bool canEventBar,
        bool canPayments,
        bool canCash)
    {
        _api = new OperationalActionsApiClient(store);
        _mainApi = new EventMenuApiClient(store);
        _canEvents = canEvents;
        _canTickets = canTickets;
        _canGuests = canGuests;
        _canEventBar = canEventBar;
        _canPayments = canPayments;
        _canCash = canCash;
        _canViewRecent = canEvents || canTickets || canGuests;
        InitializeComponent();

        TicketPanel.Visibility = canTickets ? Visibility.Visible : Visibility.Collapsed;
        GuestPanel.Visibility = canGuests ? Visibility.Visible : Visibility.Collapsed;
        AccessTab.Visibility = canTickets || canGuests ? Visibility.Visible : Visibility.Collapsed;
        PickupTab.Visibility = canEventBar ? Visibility.Visible : Visibility.Collapsed;
        BarSaleTab.Visibility = canEventBar ? Visibility.Visible : Visibility.Collapsed;
        RecentEntriesCard.Visibility = _canViewRecent ? Visibility.Visible : Visibility.Collapsed;
        BarCartGrid.ItemsSource = _barCart;

        Loaded += async (_, _) => await LoadAsync();
        Closed += (_, _) =>
        {
            foreach (var line in _barCart) line.PropertyChanged -= BarCartLine_PropertyChanged;
            _api.Dispose();
            _mainApi.Dispose();
        };
    }

    private EventOverviewItem? SelectedEvent => EventSelector.SelectedItem as EventOverviewItem;

    private async Task LoadAsync()
    {
        if (_loading) return;
        _loading = true;
        FooterStatusText.Text = "Atualizando eventos...";
        try
        {
            var selectedId = SelectedEvent?.Id;
            var eventsTask = _api.EventsOverviewAsync();
            Task<ProductsResponse>? catalogTask = _canEventBar ? _api.EventCatalogAsync() : null;
            await eventsTask;
            if (catalogTask is not null) await catalogTask;

            _events = (await eventsTask).Events;
            EventSelector.ItemsSource = _events;
            EventSelector.SelectedItem = selectedId.HasValue
                ? _events.FirstOrDefault(x => x.Id == selectedId.Value) ?? _events.FirstOrDefault()
                : _events.FirstOrDefault();

            if (catalogTask is not null)
            {
                _catalog = (await catalogTask).Products;
                ApplyBarProductFilter();
            }

            RenderSelectedEvent();
            if (_canViewRecent && SelectedEvent is not null) await LoadRecentAsync(SelectedEvent.Id);
            else RecentEntriesGrid.ItemsSource = null;
            FooterStatusText.Text = _events.Count == 0 ? "Nenhum evento disponível neste turno." : $"{_events.Count} evento(s) carregado(s).";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _loading = false;
        }
    }

    private void RenderSelectedEvent()
    {
        var selected = SelectedEvent;
        if (selected is null)
        {
            EventSummaryText.Text = "Nenhum evento selecionado.";
            EventRevenueText.Text = "";
            TicketsPaidText.Text = "—";
            TicketsCheckinText.Text = "";
            GuestsText.Text = "—";
            GuestsPendingText.Text = "";
            TicketRevenueText.Text = "—";
            BarRevenueText.Text = "—";
            return;
        }

        EventSummaryText.Text = $"{selected.Venue}{(string.IsNullOrWhiteSpace(selected.StartsDisplay) ? "" : $" • {selected.StartsDisplay}")} • {FriendlyEventStatus(selected.Status)}";
        EventRevenueText.Text = selected.RevenueDisplay;
        TicketsPaidText.Text = selected.TicketsPaid.ToString();
        TicketsCheckinText.Text = $"{selected.TicketsCheckedIn} check-in(s) • {selected.TicketsReserved} reservado(s)";
        GuestsText.Text = selected.GuestsCheckedIn.ToString();
        GuestsPendingText.Text = $"{selected.GuestsPending} pendente(s)";
        TicketRevenueText.Text = selected.TicketRevenueDisplay;
        BarRevenueText.Text = selected.BarRevenueDisplay;
    }

    private async Task LoadRecentAsync(int eventId)
    {
        if (!_canViewRecent)
        {
            RecentEntriesGrid.ItemsSource = null;
            return;
        }
        try
        {
            RecentEntriesGrid.ItemsSource = (await _api.EventRecentAsync(eventId)).Entries;
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
    }

    private async void EventSelector_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        RenderSelectedEvent();
        if (!_loading && _canViewRecent && SelectedEvent is { } selected) await LoadRecentAsync(selected.Id);
    }

    private async void TicketCheckinButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canTickets || _loading) return;
        var code = TicketCodeBox.Text.Trim();
        if (code.Length < 3)
        {
            TicketResultText.Text = "Informe ou leia o código do ingresso.";
            return;
        }
        TicketCheckinButton.IsEnabled = false;
        try
        {
            var response = await _api.EventTicketCheckInAsync(code);
            TicketResultText.Text = "Ingresso validado com sucesso" + ResultPerson(response.Result) + ".";
            TicketCodeBox.Clear();
            OperationChanged = true;
            if (SelectedEvent is { } selected) await LoadRecentAsync(selected.Id);
        }
        catch (Exception ex)
        {
            TicketResultText.Text = Friendly(ex.Message);
        }
        finally
        {
            TicketCheckinButton.IsEnabled = true;
            TicketCodeBox.Focus();
        }
    }

    private async void GuestCheckinButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canGuests || _loading) return;
        var code = GuestCodeBox.Text.Trim();
        if (code.Length < 2)
        {
            GuestResultText.Text = "Informe ou leia o código do convidado.";
            return;
        }
        GuestCheckinButton.IsEnabled = false;
        try
        {
            var response = await _api.EventGuestCheckInAsync(code);
            GuestResultText.Text = "Convidado registrado" + ResultPerson(response.Guest) + ".";
            GuestCodeBox.Clear();
            OperationChanged = true;
            if (SelectedEvent is { } selected) await LoadRecentAsync(selected.Id);
        }
        catch (Exception ex)
        {
            GuestResultText.Text = Friendly(ex.Message);
        }
        finally
        {
            GuestCheckinButton.IsEnabled = true;
            GuestCodeBox.Focus();
        }
    }

    private async void ResolvePickupButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canEventBar || SelectedEvent is not { } selected) return;
        var code = PickupCodeBox.Text.Trim();
        if (code.Length < 3)
        {
            PickupHintText.Text = "Informe ou leia o QR do pedido.";
            return;
        }
        try
        {
            _pickupCode = code;
            _pickupOrder = (await _api.ResolveEventBarOrderAsync(selected.Id, code)).Order;
            RenderPickup();
        }
        catch (Exception ex)
        {
            _pickupOrder = null;
            PickupItemsGrid.ItemsSource = null;
            PickupTitleText.Text = "Pedido não localizado";
            PickupStateText.Text = Friendly(ex.Message);
            DeliverPickupButton.IsEnabled = false;
        }
    }

    private void RenderPickup()
    {
        if (_pickupOrder is null)
        {
            PickupTitleText.Text = "Nenhum pedido lido";
            PickupStateText.Text = "";
            PickupItemsGrid.ItemsSource = null;
            DeliverPickupButton.IsEnabled = false;
            return;
        }
        PickupTitleText.Text = $"Pedido #{_pickupOrder.Id} • {_pickupOrder.TotalDisplay}";
        PickupStateText.Text = $"{_pickupOrder.StatusDisplay} • pagamento {PaymentLabel(_pickupOrder.PaymentStatus)}";
        PickupItemsGrid.ItemsSource = _pickupOrder.Items;
        DeliverPickupButton.IsEnabled = _pickupOrder.CanDeliver && !_pickupOrder.AlreadyDelivered;
        PickupHintText.Text = _pickupOrder.AlreadyDelivered
            ? "Este pedido já foi entregue anteriormente."
            : _pickupOrder.CanDeliver
                ? "Confira os itens antes de confirmar a retirada."
                : "O pedido ainda não está liberado para retirada.";
    }

    private async void DeliverPickupButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canEventBar || _pickupOrder is null || !_pickupOrder.CanDeliver || _pickupOrder.AlreadyDelivered || SelectedEvent is not { } selected) return;
        if (MessageBox.Show($"Confirmar a entrega do pedido #{_pickupOrder.Id}?", "Retirada do bar", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;
        DeliverPickupButton.IsEnabled = false;
        try
        {
            _pickupOrder = (await _api.DeliverEventBarOrderAsync(selected.Id, _pickupCode)).Order;
            OperationChanged = true;
            RenderPickup();
            if (_canViewRecent && SelectedEvent is { } current) await LoadRecentAsync(current.Id);
        }
        catch (Exception ex)
        {
            PickupHintText.Text = Friendly(ex.Message);
            RenderPickup();
        }
    }

    private void BarSearchBox_TextChanged(object sender, TextChangedEventArgs e) => ApplyBarProductFilter();

    private void ApplyBarProductFilter()
    {
        if (BarProductsGrid is null) return;
        var search = BarSearchBox?.Text.Trim() ?? "";
        IEnumerable<Product> products = _catalog;
        if (!string.IsNullOrWhiteSpace(search))
            products = products.Where(x => x.Name.Contains(search, StringComparison.OrdinalIgnoreCase) || x.Sku.Contains(search, StringComparison.OrdinalIgnoreCase));
        BarProductsGrid.ItemsSource = products.ToList();
    }

    private void BarProductsGrid_MouseDoubleClick(object sender, MouseButtonEventArgs e)
    {
        if (BarProductsGrid.SelectedItem is Product product) AddBarProduct(product);
    }

    private void AddBarProduct(Product product)
    {
        if (product.IsOutOfStock)
        {
            FooterStatusText.Text = $"{product.Name} está sem estoque.";
            return;
        }
        var line = _barCart.FirstOrDefault(x => x.ProductId == product.Id);
        var next = (line?.Quantity ?? 0) + 1;
        if (product.TrackStock == 1 && product.StockQty.HasValue && next > product.StockQty.Value)
        {
            FooterStatusText.Text = $"Estoque insuficiente para {product.Name}.";
            return;
        }
        if (line is null)
        {
            line = new CartLine { ProductId = product.Id, Name = product.Name, UnitPriceCents = product.PriceCents, Quantity = 1 };
            line.PropertyChanged += BarCartLine_PropertyChanged;
            _barCart.Add(line);
        }
        else line.Quantity += 1;
        UpdateBarTotal();
    }

    private void IncreaseBarItemButton_Click(object sender, RoutedEventArgs e)
    {
        if (BarCartGrid.SelectedItem is not CartLine line) return;
        var product = _catalog.FirstOrDefault(x => x.Id == line.ProductId);
        if (product?.TrackStock == 1 && product.StockQty.HasValue && line.Quantity + 1 > product.StockQty.Value)
        {
            FooterStatusText.Text = $"Estoque insuficiente para {line.Name}.";
            return;
        }
        line.Quantity += 1;
    }

    private void DecreaseBarItemButton_Click(object sender, RoutedEventArgs e)
    {
        if (BarCartGrid.SelectedItem is not CartLine line) return;
        if (line.Quantity <= 1) RemoveBarLine(line); else line.Quantity -= 1;
    }

    private void RemoveBarItemButton_Click(object sender, RoutedEventArgs e)
    {
        if (BarCartGrid.SelectedItem is CartLine line) RemoveBarLine(line);
    }

    private void RemoveBarLine(CartLine line)
    {
        line.PropertyChanged -= BarCartLine_PropertyChanged;
        _barCart.Remove(line);
        UpdateBarTotal();
    }

    private void BarCartLine_PropertyChanged(object? sender, PropertyChangedEventArgs e) => UpdateBarTotal();
    private void UpdateBarTotal() => BarTotalText.Text = OperationalDisplay.Money(_barCart.Sum(x => x.TotalCents));

    private async void CreateBarOrderButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canEventBar || _loading || SelectedEvent is not { } selected) return;
        if (_barCart.Count == 0)
        {
            FooterStatusText.Text = "Adicione pelo menos um produto ao pedido do bar.";
            return;
        }
        CreateBarOrderButton.IsEnabled = false;
        try
        {
            var items = _barCart.Select(x => new OrderCreateItem { ProductId = x.ProductId, Quantity = x.Quantity }).ToList();
            var created = (await _api.CreateEventBarOrderAsync(selected.Id, items, BarNotesBox.Text)).Order
                ?? throw new InvalidOperationException("O servidor não retornou o pedido criado.");

            foreach (var line in _barCart) line.PropertyChanged -= BarCartLine_PropertyChanged;
            _barCart.Clear();
            BarNotesBox.Clear();
            UpdateBarTotal();
            OperationChanged = true;
            FooterStatusText.Text = $"Pedido #{created.Id} criado no bar.";

            if (_canPayments)
            {
                var order = new Order
                {
                    Id = created.Id,
                    PublicToken = created.PublicToken,
                    Channel = string.IsNullOrWhiteSpace(created.Channel) ? "bar" : created.Channel,
                    Status = string.IsNullOrWhiteSpace(created.Status) ? "pending" : created.Status,
                    PaymentStatus = string.IsNullOrWhiteSpace(created.PaymentStatus) ? "unpaid" : created.PaymentStatus,
                    TotalCents = created.TotalCents
                };
                var payment = new OrderPaymentWindow(_mainApi, order, _canCash) { Owner = this };
                payment.ShowDialog();
                if (payment.PaymentChanged) OperationChanged = true;
            }

            _catalog = (await _api.EventCatalogAsync()).Products;
            ApplyBarProductFilter();
            await LoadAsync();
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            CreateBarOrderButton.IsEnabled = true;
        }
    }

    private static string FriendlyEventStatus(string status) => status switch
    {
        "published" => "Publicado",
        "draft" => "Rascunho",
        "closed" => "Encerrado",
        _ => status
    };

    private static string PaymentLabel(string status) => status switch
    {
        "paid" => "pago",
        "partially_paid" => "parcial",
        "pending" => "pendente",
        "unpaid" => "não pago",
        "failed" => "falhou",
        "refunded" => "estornado",
        _ => status
    };

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string ResultPerson(Dictionary<string, JsonElement>? data)
    {
        if (data is null) return "";
        foreach (var key in new[] { "name", "person_name", "holder_name", "guest_name" })
        {
            if (!data.TryGetValue(key, out var value)) continue;
            var text = value.ToString().Trim();
            if (!string.IsNullOrWhiteSpace(text)) return $" • {text}";
        }
        return "";
    }

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível concluir a operação. Tente novamente."
            : message;
    }
}
