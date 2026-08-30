using System.Windows;
using TaxiOperator.Services;

namespace TaxiOperator.Views;

public partial class SipSettingsWindow : Window
{
    public SipSettings Settings { get; }
    public bool Saved { get; private set; }

    public SipSettingsWindow(SipSettings settings)
    {
        InitializeComponent();
        Settings = settings;

        EnabledBox.IsChecked = settings.Enabled;
        ServerBox.Text = settings.Server;
        UserBox.Text = settings.Username;
        PassBox.Password = settings.Password;
        DisplayBox.Text = settings.DisplayName;
        AutoAnswerBox.IsChecked = settings.AutoAnswer;
        AutoFillBox.IsChecked = settings.AutoFillPhone;

        var inputs = SipService.GetInputDevices();
        var outputs = SipService.GetOutputDevices();
        foreach (var d in inputs) InputBox.Items.Add($"{d.Index}: {d.Name}");
        foreach (var d in outputs) OutputBox.Items.Add($"{d.Index}: {d.Name}");
        InputBox.SelectedIndex = Math.Max(0, inputs.FindIndex(d => d.Index == settings.InputDeviceIndex));
        OutputBox.SelectedIndex = Math.Max(0, outputs.FindIndex(d => d.Index == settings.OutputDeviceIndex));

        HintText.Text = "Укажите данные внутреннего номера, выданные вашей АТС "
            + "(Asterisk, FreePBX, Mango, Zadarma и др.). Гарнитуру подключите до запуска программы.";
    }

    private void OnSave(object sender, RoutedEventArgs e)
    {
        Settings.Enabled = EnabledBox.IsChecked == true;
        Settings.Server = ServerBox.Text.Trim();
        Settings.Username = UserBox.Text.Trim();
        Settings.Password = PassBox.Password;
        Settings.DisplayName = DisplayBox.Text.Trim();
        Settings.AutoAnswer = AutoAnswerBox.IsChecked == true;
        Settings.AutoFillPhone = AutoFillBox.IsChecked == true;

        Settings.InputDeviceIndex = ParseIndex(InputBox.SelectedItem?.ToString());
        Settings.OutputDeviceIndex = ParseIndex(OutputBox.SelectedItem?.ToString());

        Settings.Save();
        Saved = true;
        DialogResult = true;
        Close();
    }

    private static int ParseIndex(string? item)
    {
        if (string.IsNullOrEmpty(item)) return -1;
        var part = item.Split(':')[0];
        return int.TryParse(part, out var idx) ? idx : -1;
    }

    private void OnCancel(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }
}
